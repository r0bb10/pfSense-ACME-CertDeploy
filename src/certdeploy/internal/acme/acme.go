package acme

import (
	"crypto/sha256"
	"crypto/x509"
	"encoding/hex"
	"encoding/pem"
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
	Paths       Paths
	Fingerprint string
}

type Paths struct {
	Cert      string
	Key       string
	Fullchain string
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
	// Placeholder: real implementation will parse config.xml.
	// For now return an empty slice so the daemon compiles.
	return []Certificate{}, nil
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

// FilesStable returns true if the certificate files have not changed over two reads.
func FilesStable(paths Paths, waitSeconds int) bool {
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

func statFiles(paths Paths) (string, error) {
	c, err := os.Stat(paths.Cert)
	if err != nil {
		return "", err
	}
	k, err := os.Stat(paths.Key)
	if err != nil {
		return "", err
	}
	f, err := os.Stat(paths.Fullchain)
	if err != nil {
		return "", err
	}
	return fmt.Sprintf("%d:%d/%d:%d/%d:%d", c.Size(), c.ModTime().Unix(), k.Size(), k.ModTime().Unix(), f.Size(), f.ModTime().Unix()), nil
}
