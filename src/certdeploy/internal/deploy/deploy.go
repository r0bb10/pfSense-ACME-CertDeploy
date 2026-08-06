package deploy

import (
	"context"
	"fmt"
	"log/slog"
	"path/filepath"
	"strings"
	"time"

	"certdeploy/internal/config"
	"certdeploy/internal/ssh"
	"certdeploy/internal/state"
)

// Engine executes a single deployment.
type Engine struct {
	cfg    *config.Config
	logger *slog.Logger
	state  *state.Manager
	ssh    *ssh.Runner
}

func NewEngine(cfg *config.Config, logger *slog.Logger, sm *state.Manager, runner *ssh.Runner) *Engine {
	return &Engine{cfg: cfg, logger: logger, state: sm, ssh: runner}
}

// Artifact mirrors the config.xml artifact row.
type Artifact struct {
	Type      string
	Filename  string
	Owner     string
	FileGroup string
	Mode      string
}

// Destination mirrors the config.xml destination row.
type Destination struct {
	Directory string
	CreateDir bool
	DirOwner  string
	DirGroup  string
	DirMode   string
}

// Deployment mirrors the config.xml deployment item.
type Deployment struct {
	ID               string
	Name             string
	Enabled          bool
	CertificateName  string
	CertificatePaths struct {
		Cert      string
		Key       string
		Fullchain string
	}
	Host               string
	Port               int
	User               string
	IdentityPath       string
	HostKeyFingerprint string
	Timeout            int
	CMDTimeout         int
	UseSFTP            bool
	LegacySCPFallback  bool
	ExtraSSHOpts       string
	Artifacts          []Artifact
	Destinations       []Destination
	PostUploadCmd      string
	ValidationCmd      string
	ReloadCmd          string
}

// Run executes the deployment and records state.
func (e *Engine) Run(ctx context.Context, d Deployment) error {
	if !d.Enabled {
		return fmt.Errorf("deployment is disabled")
	}

	opts := ssh.Options{
		Host:               d.Host,
		Port:               d.Port,
		User:               d.User,
		IdentityPath:       d.IdentityPath,
		HostKeyFingerprint: d.HostKeyFingerprint,
		Timeout:            d.Timeout,
		CMDTimeout:         d.CMDTimeout,
		ExtraSSHOpts:       d.ExtraSSHOpts,
		UseSFTP:            d.UseSFTP,
		LegacySCPFallback:  d.LegacySCPFallback,
	}

	e.logger.Info("starting deployment", slog.String("component", "deploy"), slog.String("deployment", d.ID), slog.String("name", d.Name))

	// 1. Create remote directories.
	for _, dest := range d.Destinations {
		if dest.CreateDir {
			cmd := fmt.Sprintf("mkdir -p %s", shellQuote(dest.Directory))
			if _, err := e.ssh.RunCommand(ctx, opts, cmd); err != nil {
				return e.record(d.ID, "failed", err)
			}
			if dest.DirMode != "" {
				cmd = fmt.Sprintf("chmod %s %s", dest.DirMode, shellQuote(dest.Directory))
				if _, err := e.ssh.RunCommand(ctx, opts, cmd); err != nil {
					return e.record(d.ID, "failed", err)
				}
			}
			if dest.DirOwner != "" || dest.DirGroup != "" {
				owner := dest.DirOwner
				if owner == "" {
					owner = "root"
				}
				group := dest.DirGroup
				if group == "" {
					group = owner
				}
				cmd = fmt.Sprintf("chown %s:%s %s", owner, group, shellQuote(dest.Directory))
				if _, err := e.ssh.RunCommand(ctx, opts, cmd); err != nil {
					return e.record(d.ID, "failed", err)
				}
			}
		}
	}

	// 2. Upload artifacts to temporary names and rename.
	for _, dest := range d.Destinations {
		for _, art := range d.Artifacts {
			src, err := sourcePath(d.CertificatePaths, art.Type)
			if err != nil {
				return e.record(d.ID, "failed", err)
			}
			final := filepath.Join(dest.Directory, art.Filename)
			// Use a stable temp name pattern.
			tmp := final + ".certdeploy-tmp"
			if err := e.ssh.CopyFile(ctx, opts, src, tmp); err != nil {
				return e.record(d.ID, "failed", err)
			}
			// Apply ownership/mode on temp file.
			if art.Mode != "" {
				cmd := fmt.Sprintf("chmod %s %s", art.Mode, shellQuote(tmp))
				if _, err := e.ssh.RunCommand(ctx, opts, cmd); err != nil {
					return e.record(d.ID, "failed", err)
				}
			}
		if art.Owner != "" || art.FileGroup != "" {
			owner := art.Owner
			if owner == "" {
				owner = "root"
			}
			group := art.FileGroup
			if group == "" {
				group = owner
			}
				cmd := fmt.Sprintf("chown %s:%s %s", owner, group, shellQuote(tmp))
				if _, err := e.ssh.RunCommand(ctx, opts, cmd); err != nil {
					return e.record(d.ID, "failed", err)
				}
			}
		}
	}

	// 3. Post-upload command.
	if d.PostUploadCmd != "" {
		if _, err := e.ssh.RunCommand(ctx, opts, d.PostUploadCmd); err != nil {
			return e.record(d.ID, "failed", err)
		}
	}

	// 4. Rename temp files into place.
	for _, dest := range d.Destinations {
		for _, art := range d.Artifacts {
			final := filepath.Join(dest.Directory, art.Filename)
			tmp := final + ".certdeploy-tmp"
			cmd := fmt.Sprintf("mv -f %s %s", shellQuote(tmp), shellQuote(final))
			if _, err := e.ssh.RunCommand(ctx, opts, cmd); err != nil {
				return e.record(d.ID, "failed", err)
			}
		}
	}

	// 5. Validation command.
	if d.ValidationCmd != "" {
		if _, err := e.ssh.RunCommand(ctx, opts, d.ValidationCmd); err != nil {
			return e.record(d.ID, "failed", err)
		}
	}

	// 6. Reload command.
	if d.ReloadCmd != "" {
		if _, err := e.ssh.RunCommand(ctx, opts, d.ReloadCmd); err != nil {
			return e.record(d.ID, "failed", err)
		}
	}

	// 7. Record success.
	e.logger.Info("deployment completed", slog.String("component", "deploy"), slog.String("deployment", d.ID), slog.String("name", d.Name))
	return e.record(d.ID, "success", nil)
}

func (e *Engine) record(id, status string, err error) error {
	ds := state.DeploymentState{LastRunStatus: status, LastDeployedTime: time.Now().Unix()}
	if err != nil {
		e.logger.Error("deployment failed", slog.String("component", "deploy"), slog.String("deployment", id), slog.Any("error", err))
		ds.LastRunStatus = "failed"
	}
	if err := e.state.UpdateDeployment(id, ds); err != nil {
		e.logger.Error("failed to save state", slog.String("component", "state"), slog.String("deployment", id), slog.Any("error", err))
	}
	if err != nil {
		return err
	}
	return nil
}

func sourcePath(paths struct {
	Cert      string
	Key       string
	Fullchain string
}, artType string) (string, error) {
	switch artType {
	case "crt":
		return paths.Cert, nil
	case "key":
		return paths.Key, nil
	case "fullchain":
		return paths.Fullchain, nil
	default:
		return "", fmt.Errorf("unknown artifact type: %s", artType)
	}
}

func shellQuote(s string) string {
	return "'" + strings.ReplaceAll(s, "'", "'\\''") + "'"
}
