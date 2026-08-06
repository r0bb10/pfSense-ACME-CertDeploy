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
	Source    string // ACME filename, e.g. "sp8t.com.crt"
	Filename  string // Remote filename
	Owner     string
	FileGroup string
	Mode      string
}

// Destination mirrors the config.xml destination item.
type Destination struct {
	Directory string
	DirOwner  string
	DirGroup  string
	DirMode   string
}

// Deployment mirrors the config.xml deployment item.
type Deployment struct {
	ID                 string
	Name               string
	Enabled            bool
	CertificateName    string
	CertificateBase    string // /conf/acme/<descr>
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
	Destination        Destination
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

	dest := d.Destination
	has_files := dest.Directory != "" && len(d.Artifacts) > 0

	// 1. Create remote directory and apply ownership/mode when files are requested.
	if has_files {
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

	// 2. Upload artifacts to temporary names and apply ownership/mode.
	if has_files {
		for _, art := range d.Artifacts {
			src := filepath.Join(d.CertificateBase, art.Source)
			final := filepath.Join(dest.Directory, art.Filename)
			// Use a stable temp name pattern.
			tmp := final + ".certdeploy-tmp"
			if err := e.ssh.CopyFile(ctx, opts, src, tmp); err != nil {
				return e.record(d.ID, "failed", err)
			}
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
	if has_files && d.PostUploadCmd != "" {
		if _, err := e.ssh.RunCommand(ctx, opts, d.PostUploadCmd); err != nil {
			return e.record(d.ID, "failed", err)
		}
	}

	// 4. Rename temp files into place.
	if has_files {
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
	if has_files && d.ValidationCmd != "" {
		if _, err := e.ssh.RunCommand(ctx, opts, d.ValidationCmd); err != nil {
			return e.record(d.ID, "failed", err)
		}
	}

	// 6. Reload command.
	if has_files && d.ReloadCmd != "" {
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

func shellQuote(s string) string {
	return "'" + strings.ReplaceAll(s, "'", "'\\''") + "'"
}
