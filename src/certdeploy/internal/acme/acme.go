package acme

import (
	"bytes"
	"crypto"
	"crypto/sha256"
	"crypto/x509"
	"encoding/hex"
	"encoding/pem"
	"encoding/xml"
	"fmt"
	"os"
	"time"

	"certdeploy/internal/config"
)

// Certificate represents a discovered ACME certificate.
type Certificate struct {
	Name        string
	Description string
	Status      string
	LastRenewal int64
	ValidFrom   string
	ValidTo     string
	CN          string
	SANs        []string
	Paths       CertificatePaths
	Fingerprint string
}

// CertificatePaths identifies the ACME files for one certificate.
type CertificatePaths struct {
	Cert      string
	Key       string
	FullChain string
}

// pfSenseXML mirrors the parts of /cf/conf/config.xml needed for ACME discovery.
type pfSenseXML struct {
	InstalledPkgs struct {
		ACME struct {
			Certificates struct {
				Items []acmeCertXML `xml:"item"`
			} `xml:"certificates"`
		} `xml:"acme"`
	} `xml:"installedpackages"`
}

type acmeCertXML struct {
	Name        string            `xml:"name"`
	Description string            `xml:"descr"`
	Status      string            `xml:"status"`
	LastRenewal int64             `xml:"lastrenewal"`
	DomainList  acmeDomainListXML `xml:"a_domainlist"`
}

type acmeDomainListXML struct {
	Items []acmeDomainXML `xml:"item"`
}

type acmeDomainXML struct {
	Name   string `xml:"name"`
	Status string `xml:"status"`
}

// Discoverer reads ACME certificate state from pfSense config.xml.
type Discoverer struct {
	cfg *config.Config
}

func New(cfg *config.Config) *Discoverer {
	return &Discoverer{cfg: cfg}
}

// Discover returns ACME certificates available for deployment.
func (d *Discoverer) Discover() ([]Certificate, error) {
	data, err := os.ReadFile(d.cfg.ConfigPath)
	if err != nil {
		return nil, fmt.Errorf("failed to read config: %w", err)
	}

	var parsed pfSenseXML
	if err := xml.Unmarshal(data, &parsed); err != nil {
		return nil, fmt.Errorf("failed to parse config: %w", err)
	}

	var certs []Certificate
	for _, raw := range parsed.InstalledPkgs.ACME.Certificates.Items {
		if raw.Name == "" {
			continue
		}
		paths := CertificatePaths{
			Cert:      fmt.Sprintf("/conf/acme/%s.crt", raw.Name),
			Key:       fmt.Sprintf("/conf/acme/%s.key", raw.Name),
			FullChain: fmt.Sprintf("/conf/acme/%s.fullchain", raw.Name),
		}
		cert := Certificate{
			Name:        raw.Name,
			Description: raw.Description,
			Status:      raw.Status,
			LastRenewal: raw.LastRenewal,
			Paths:       paths,
		}

		for _, domain := range raw.DomainList.Items {
			if domain.Status == "disable" {
				continue
			}
			if domain.Name != "" {
				cert.SANs = append(cert.SANs, domain.Name)
			}
		}
		if len(cert.SANs) > 0 {
			cert.CN = cert.SANs[0]
		}

		if _, err := os.Stat(paths.FullChain); err == nil {
			fp, err := Fingerprint(paths.FullChain)
			if err == nil {
				cert.Fingerprint = fp
			}
			if c, err := ParseCertificate(paths.Cert); err == nil {
				cert.ValidFrom = c.NotBefore.Format(time.RFC3339)
				cert.ValidTo = c.NotAfter.Format(time.RFC3339)
				if cert.CN == "" {
					cert.CN = c.Subject.CommonName
				}
			}
		}

		certs = append(certs, cert)
	}

	return certs, nil
}

// Fingerprint returns SHA256 of the fullchain file content.
func Fingerprint(path string) (string, error) {
	data, err := os.ReadFile(path)
	if err != nil {
		return "", err
	}
	h := sha256.Sum256(data)
	return "SHA256:" + hex.EncodeToString(h[:]), nil
}

// ParseCertificate extracts basic metadata from a PEM certificate file.
func ParseCertificate(path string) (*x509.Certificate, error) {
	data, err := os.ReadFile(path)
	if err != nil {
		return nil, err
	}
	block, _ := pem.Decode(data)
	if block == nil {
		return nil, fmt.Errorf("no PEM block found in %s", path)
	}
	return x509.ParseCertificate(block.Bytes)
}

// ValidateKeyPair verifies that the private key belongs to the certificate.
func ValidateKeyPair(certPath, keyPath string) error {
	cert, err := ParseCertificate(certPath)
	if err != nil {
		return fmt.Errorf("parse certificate: %w", err)
	}
	keyData, err := os.ReadFile(keyPath)
	if err != nil {
		return fmt.Errorf("read private key: %w", err)
	}
	block, _ := pem.Decode(keyData)
	if block == nil {
		return fmt.Errorf("no private key PEM block found in %s", keyPath)
	}
	key, err := parsePrivateKey(block.Bytes)
	if err != nil {
		return fmt.Errorf("parse private key: %w", err)
	}
	certPublic, err := x509.MarshalPKIXPublicKey(cert.PublicKey)
	if err != nil {
		return fmt.Errorf("marshal certificate public key: %w", err)
	}
	keyPublic, err := x509.MarshalPKIXPublicKey(key.Public())
	if err != nil {
		return fmt.Errorf("marshal private key public key: %w", err)
	}
	if !bytes.Equal(certPublic, keyPublic) {
		return fmt.Errorf("certificate and private key do not match")
	}
	return nil
}

func parsePrivateKey(der []byte) (crypto.Signer, error) {
	if key, err := x509.ParsePKCS8PrivateKey(der); err == nil {
		if signer, ok := key.(crypto.Signer); ok {
			return signer, nil
		}
	}
	if key, err := x509.ParsePKCS1PrivateKey(der); err == nil {
		return key, nil
	}
	if key, err := x509.ParseECPrivateKey(der); err == nil {
		return key, nil
	}
	return nil, fmt.Errorf("unsupported private key format")
}

// CertificateFilesStable returns true if certificate files are unchanged over two reads.
func CertificateFilesStable(paths CertificatePaths, waitSeconds int) bool {
	stat1, err := statFiles(paths)
	if err != nil {
		return false
	}
	if waitSeconds > 0 {
		time.Sleep(time.Duration(waitSeconds) * time.Second)
	}
	stat2, err := statFiles(paths)
	if err != nil {
		return false
	}
	return stat1 == stat2
}

func statFiles(paths CertificatePaths) (string, error) {
	c, err := os.Stat(paths.Cert)
	if err != nil {
		return "", err
	}
	k, err := os.Stat(paths.Key)
	if err != nil {
		return "", err
	}
	f, err := os.Stat(paths.FullChain)
	if err != nil {
		return "", err
	}
	return fmt.Sprintf("%d:%d/%d:%d/%d:%d", c.Size(), c.ModTime().Unix(), k.Size(), k.ModTime().Unix(), f.Size(), f.ModTime().Unix()), nil
}
