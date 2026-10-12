package pfsense

import (
	"encoding/xml"
	"fmt"
	"os"

	"certdeploy/internal/deploy"
)

// pfSenseConfig mirrors the parts of /cf/conf/config.xml we need.
type pfSenseConfig struct {
	XMLName       xml.Name      `xml:"pfsense"`
	InstalledPkgs installedPkgs `xml:"installedpackages"`
}

type installedPkgs struct {
	CertDeploy certDeployXML `xml:"certdeploy"`
	ACME       acmeXML       `xml:"acme"`
}

type certDeployXML struct {
	Identities struct {
		Items []identityXML `xml:"item"`
	} `xml:"identities"`
	Deployments struct {
		Items []deploymentXML `xml:"item"`
	} `xml:"deployments"`
}

type identityXML struct {
	ID             string `xml:"id"`
	Name           string `xml:"name"`
	PrivateKeyPath string `xml:"private_key_path"`
}

type deploymentXML struct {
	ID                     string         `xml:"id"`
	Name                   string         `xml:"name"`
	Enabled                string         `xml:"enabled"`
	CertificateName        string         `xml:"certificate_name"`
	Host                   string         `xml:"host"`
	Port                   int            `xml:"port"`
	IdentityID             string         `xml:"identity_id"`
	HostKeyFingerprint     string         `xml:"host_key_fingerprint"`
	HostKeyIgnoreChanges   string         `xml:"host_key_ignore_changes"`
	ConnectTimeout         int            `xml:"connect_timeout"`
	CommandTimeout         int            `xml:"cmd_timeout"`
	LegacySCPFallback      string         `xml:"legacy_scp_fallback"`
	DisableConnectionReuse string         `xml:"disable_connection_reuse"`
	AutoDeploy             string         `xml:"auto_deploy"`
	VerifyFiles            string         `xml:"verify_files"`
	Artifacts              artifactsXML   `xml:"artifacts"`
	Destination            destinationXML `xml:"destination"`
	ExtraDestinations      extraDestsXML  `xml:"extra_destinations"`
	PostUploadCmd          string         `xml:"post_upload_cmd"`
	ValidationCmd          string         `xml:"validation_cmd"`
	ReloadCmd              string         `xml:"reload_cmd"`
}

type artifactsXML struct {
	Rows []artifactXML `xml:"row"`
}

type artifactXML struct {
	Source   string `xml:"source"`
	Filename string `xml:"filename"`
}

type destinationXML struct {
	Directory string `xml:"directory"`
	Owner     string `xml:"owner"`
	Group     string `xml:"group"`
	Mode      string `xml:"mode"`
	// Deprecated: legacy dir-level fields, kept for backward compat.
	DirOwner string `xml:"dir_owner"`
	DirGroup string `xml:"dir_group"`
	DirMode  string `xml:"dir_mode"`
}

type extraDestsXML struct {
	Items []destinationXML `xml:"item"`
}

type acmeXML struct {
	Certificates struct {
		Items []acmeCertXML `xml:"item"`
	} `xml:"certificates"`
}

type acmeCertXML struct {
	Name  string `xml:"name"`
	Descr string `xml:"descr"`
}

func parseBool(s string) bool {
	return s == "on" || s == "true" || s == "1"
}

// LoadDeployment loads a deployment by ID from the pfSense config.xml and
// resolves its identity and ACME certificate.
func LoadDeployment(configPath, deploymentID string) (deploy.Deployment, error) {
	var empty deploy.Deployment
	cfg, err := loadConfig(configPath)
	if err != nil {
		return empty, err
	}

	raw := findDeployment(cfg, deploymentID)
	if raw == nil {
		return empty, fmt.Errorf("deployment not found: %s", deploymentID)
	}

	identity := findIdentity(cfg, raw.IdentityID)
	if identity == nil {
		return empty, fmt.Errorf("identity not found: %s", raw.IdentityID)
	}

	d := mapDeployment(raw, cfg, identity)

	return d, nil
}

func loadConfig(path string) (pfSenseConfig, error) {
	data, err := os.ReadFile(path)
	if err != nil {
		return pfSenseConfig{}, fmt.Errorf("read config: %w", err)
	}
	var cfg pfSenseConfig
	if err := xml.Unmarshal(data, &cfg); err != nil {
		return pfSenseConfig{}, fmt.Errorf("parse config: %w", err)
	}
	return cfg, nil
}

func findDeployment(cfg pfSenseConfig, id string) *deploymentXML {
	for i := range cfg.InstalledPkgs.CertDeploy.Deployments.Items {
		deployment := &cfg.InstalledPkgs.CertDeploy.Deployments.Items[i]
		if deployment.ID == id {
			return deployment
		}
	}
	return nil
}

func findIdentity(cfg pfSenseConfig, id string) *identityXML {
	for i := range cfg.InstalledPkgs.CertDeploy.Identities.Items {
		identity := &cfg.InstalledPkgs.CertDeploy.Identities.Items[i]
		if identity.ID == id {
			return identity
		}
	}
	return nil
}

// mapDeployment converts a parsed deploymentXML into a deploy.Deployment,
// resolving legacy permission fields where the new ones are unset.
func mapDeployment(raw *deploymentXML, cfg pfSenseConfig, identity *identityXML) deploy.Deployment {
	d := deploy.Deployment{
		ID:                     raw.ID,
		Name:                   raw.Name,
		Enabled:                parseBool(raw.Enabled),
		CertificateName:        raw.CertificateName,
		Host:                   raw.Host,
		Port:                   raw.Port,
		User:                   identity.Name,
		IdentityPath:           identity.PrivateKeyPath,
		IdentityID:             raw.IdentityID,
		HostKeyFingerprint:     raw.HostKeyFingerprint,
		HostKeyIgnoreChanges:   parseBool(raw.HostKeyIgnoreChanges),
		Timeout:                raw.ConnectTimeout,
		CommandTimeout:         raw.CommandTimeout,
		LegacySCPFallback:      parseBool(raw.LegacySCPFallback),
		DisableConnectionReuse: parseBool(raw.DisableConnectionReuse),
		AutoDeploy:             parseBool(raw.AutoDeploy),
		VerifyFiles:            raw.VerifyFiles == "" || parseBool(raw.VerifyFiles),
		Destination:            mapDestination(raw.Destination),
		PostUploadCmd:          raw.PostUploadCmd,
		ValidationCmd:          raw.ValidationCmd,
		ReloadCmd:              raw.ReloadCmd,
	}

	for _, art := range raw.Artifacts.Rows {
		d.Artifacts = append(d.Artifacts, deploy.Artifact{
			Source:   art.Source,
			Filename: art.Filename,
		})
	}
	for _, ed := range raw.ExtraDestinations.Items {
		d.ExtraDestinations = append(d.ExtraDestinations, mapDestination(ed))
	}
	if raw.CertificateName != "" {
		d.CertificateBase = certificateBase(cfg.InstalledPkgs.ACME, raw.CertificateName)
	}

	return d
}

// mapDestination maps a destinationXML into a deploy.Destination, carrying the
// per-file permutation over from legacy dir-level fields when not set.
func mapDestination(x destinationXML) deploy.Destination {
	d := deploy.Destination{Directory: x.Directory}
	d.Owner = x.Owner
	if d.Owner == "" {
		d.Owner = x.DirOwner
	}
	d.Group = x.Group
	if d.Group == "" {
		d.Group = x.DirGroup
	}
	d.Mode = x.Mode
	if d.Mode == "" {
		d.Mode = x.DirMode
	}
	return d
}

func certificateBase(acme acmeXML, name string) string {
	for _, cert := range acme.Certificates.Items {
		if cert.Name == name {
			return "/conf/acme"
		}
	}
	return ""
}

// LoadAllDeployments loads all configured deployments from config.xml.
func LoadAllDeployments(configPath string) ([]deploy.Deployment, error) {
	cfg, err := loadConfig(configPath)
	if err != nil {
		return nil, err
	}

	var deployments []deploy.Deployment
	for i := range cfg.InstalledPkgs.CertDeploy.Deployments.Items {
		raw := &cfg.InstalledPkgs.CertDeploy.Deployments.Items[i]
		identity := findIdentity(cfg, raw.IdentityID)
		if identity == nil {
			continue
		}

		d := mapDeployment(raw, cfg, identity)
		deployments = append(deployments, d)
	}

	return deployments, nil
}
