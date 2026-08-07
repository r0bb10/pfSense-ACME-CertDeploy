package deploy

import (
	"context"
	"fmt"
	"log/slog"
	"path/filepath"
	"strings"
	"time"

	"certdeploy/internal/acme"
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
	ID                     string
	Name                   string
	Enabled                bool
	CertificateName        string
	CertificateBase        string // /conf/acme/<descr>
	Host                   string
	Port                   int
	User                   string
	IdentityPath           string
	HostKeyFingerprint     string
	HostKeyIgnoreChanges   bool
	Timeout                int
	CMDTimeout             int
	UseSFTP                bool
	LegacySCPFallback      bool
	DisableConnectionReuse bool
	AutoDeploy             bool
	ExtraSSHOpts           string
	Artifacts              []Artifact
	Destination            Destination
	PostUploadCmd          string
	ValidationCmd          string
	ReloadCmd              string
}

// RunResult contains the outcome of a deployment run.
type RunResult struct {
	HostKeyFingerprint string
	CurrentFingerprint string
	Error              error
}

// Run executes the deployment and records state.
func (e *Engine) Run(ctx context.Context, d Deployment) RunResult {
	log := e.logger.With(slog.String("deployment", d.ID), slog.String("name", d.Name))
	start := time.Now()
	result := RunResult{}

	fail := func(step string, err error) RunResult {
		result.Error = fmt.Errorf("%s: %w", step, err)
		e.record(d.ID, "failed", result.HostKeyFingerprint, "", result.Error)
		return result
	}

	if !d.Enabled {
		return fail("deployment disabled", fmt.Errorf("deployment is disabled"))
	}

	opts := ssh.Options{
		Host:                 d.Host,
		Port:                 d.Port,
		User:                 d.User,
		IdentityPath:         d.IdentityPath,
		HostKeyFingerprint:   d.HostKeyFingerprint,
		HostKeyIgnoreChanges: d.HostKeyIgnoreChanges,
		Timeout:              d.Timeout,
		CMDTimeout:           d.CMDTimeout,
		ExtraSSHOpts:         d.ExtraSSHOpts,
		UseSFTP:              d.UseSFTP,
		LegacySCPFallback:    d.LegacySCPFallback,
	}

	log.Info("starting deployment", slog.String("host", d.Host))

	// Scan and verify host key. This also captures the fingerprint for new targets.
	log.Debug("scanning host key")
	khFile, cleanup, fingerprint, algorithm, err := e.ssh.KnownHostsFileFor(ctx, opts)
	if err != nil {
		return fail("host key verification", err)
	}
	if cleanup != nil {
		defer cleanup()
	}
	result.HostKeyFingerprint = fingerprint
	opts.KnownHostsFile = khFile
	opts.HostKeyAlgorithm = algorithm

	if !d.DisableConnectionReuse {
		log.Debug("starting SSH control master")
		socket, cleanupMaster, err := e.ssh.StartControlMaster(ctx, opts)
		if err != nil {
			return fail("start control master", err)
		}
		defer cleanupMaster()
		opts.ControlSocket = socket
	}

	dest := d.Destination
	hasFiles := dest.Directory != "" && len(d.Artifacts) > 0

	// 1. Create remote directory and apply ownership/mode when files are requested.
	if hasFiles {
		log.Debug("creating remote directory", slog.String("directory", dest.Directory))
		cmd := fmt.Sprintf("mkdir -p %s", shellQuote(dest.Directory))
		if _, err := e.ssh.RunCommand(ctx, opts, cmd); err != nil {
			return fail("create remote directory", err)
		}
		if dest.DirMode != "" {
			log.Debug("setting directory mode", slog.String("directory", dest.Directory), slog.String("mode", dest.DirMode))
			cmd = fmt.Sprintf("chmod %s %s", dest.DirMode, shellQuote(dest.Directory))
			if _, err := e.ssh.RunCommand(ctx, opts, cmd); err != nil {
				return fail("chmod remote directory", err)
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
			log.Debug("setting directory ownership", slog.String("directory", dest.Directory), slog.String("owner", owner), slog.String("group", group))
			cmd = fmt.Sprintf("chown %s:%s %s", owner, group, shellQuote(dest.Directory))
			if _, err := e.ssh.RunCommand(ctx, opts, cmd); err != nil {
				return fail("chown remote directory", err)
			}
		}
	}

	// 2. Upload artifacts to temporary names and apply ownership/mode.
	if hasFiles {
		for _, art := range d.Artifacts {
			src := filepath.Join(d.CertificateBase, art.Source)
			final := filepath.Join(dest.Directory, art.Filename)
			// Use a stable temp name pattern.
			tmp := final + ".certdeploy-tmp"
			log.Debug("uploading artifact", slog.String("source", src), slog.String("destination", tmp))
			if err := e.ssh.CopyFile(ctx, opts, src, tmp); err != nil {
				return fail("upload artifact "+art.Filename, err)
			}
			if art.Mode != "" {
				log.Debug("setting artifact mode", slog.String("file", art.Filename), slog.String("mode", art.Mode))
				cmd := fmt.Sprintf("chmod %s %s", art.Mode, shellQuote(tmp))
				if _, err := e.ssh.RunCommand(ctx, opts, cmd); err != nil {
					return fail("chmod artifact "+art.Filename, err)
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
				log.Debug("setting artifact ownership", slog.String("file", art.Filename), slog.String("owner", owner), slog.String("group", group))
				cmd := fmt.Sprintf("chown %s:%s %s", owner, group, shellQuote(tmp))
				if _, err := e.ssh.RunCommand(ctx, opts, cmd); err != nil {
					return fail("chown artifact "+art.Filename, err)
				}
			}
		}
	}

	// 3. Post-upload command.
	if hasFiles && d.PostUploadCmd != "" {
		log.Debug("running post-upload command")
		if _, err := e.ssh.RunCommand(ctx, opts, d.PostUploadCmd); err != nil {
			return fail("post-upload command", err)
		}
	}

	// 4. Rename temp files into place.
	if hasFiles {
		for _, art := range d.Artifacts {
			final := filepath.Join(dest.Directory, art.Filename)
			tmp := final + ".certdeploy-tmp"
			log.Debug("moving artifact into place", slog.String("file", art.Filename))
			cmd := fmt.Sprintf("mv -f %s %s", shellQuote(tmp), shellQuote(final))
			if _, err := e.ssh.RunCommand(ctx, opts, cmd); err != nil {
				return fail("move artifact into place", err)
			}
		}
	}

	// 5. Validation command.
	if hasFiles && d.ValidationCmd != "" {
		log.Debug("running validation command")
		if _, err := e.ssh.RunCommand(ctx, opts, d.ValidationCmd); err != nil {
			return fail("validation command", err)
		}
	}

	// 6. Reload command.
	if hasFiles && d.ReloadCmd != "" {
		log.Debug("running reload command")
		if _, err := e.ssh.RunCommand(ctx, opts, d.ReloadCmd); err != nil {
			return fail("reload command", err)
		}
	}

	// 7. Record success and current certificate fingerprint.
	if hasFiles && d.CertificateName != "" {
		fp, fpErr := acme.Fingerprint(filepath.Join(d.CertificateBase, d.CertificateName+".fullchain"))
		if fpErr == nil {
			result.CurrentFingerprint = fp
		}
	}
	log.Info("deployment completed", slog.Duration("duration", time.Since(start)))
	e.record(d.ID, "success", result.HostKeyFingerprint, result.CurrentFingerprint, nil)
	return result
}

func (e *Engine) record(id, status, hostKeyFingerprint, certFingerprint string, err error) {
	ds := state.DeploymentState{
		LastRunStatus:           status,
		LastDeployedTime:        time.Now().Unix(),
		HostKeyFingerprint:      hostKeyFingerprint,
		LastDeployedFingerprint: certFingerprint,
	}
	if err != nil {
		ds.LastRunStatus = "failed"
	}
	if err := e.state.UpdateDeployment(id, ds); err != nil {
		e.logger.Error("failed to save state",
			slog.String("deployment", id), slog.Any("error", err))
	}
}

func shellQuote(s string) string {
	return "'" + strings.ReplaceAll(s, "'", "'\\''") + "'"
}
