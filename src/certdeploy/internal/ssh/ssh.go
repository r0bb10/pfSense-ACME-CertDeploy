package ssh

import (
	"context"
	"fmt"
	"log/slog"
	"os"
	"os/exec"
	"strings"
	"time"

	"certdeploy/internal/config"
)

// Runner executes SSH and SCP using system OpenSSH tools.
type Runner struct {
	cfg    *config.Config
	logger *slog.Logger
}

func NewRunner(cfg *config.Config, logger *slog.Logger) *Runner {
	return &Runner{cfg: cfg, logger: logger}
}

// Options configures a single target connection.
type Options struct {
	Host               string
	Port               int
	User               string
	IdentityPath       string
	HostKeyFingerprint string
	Timeout            int
	CMDTimeout         int
	ExtraSSHOpts       string
	UseSFTP            bool
	LegacySCPFallback  bool
}

func (r *Runner) execSSH(ctx context.Context, opts Options, remoteCmd string) ([]byte, error) {
	args := r.baseArgs(opts)
	args = append(args, "-o", "BatchMode=yes")
	args = append(args, fmt.Sprintf("%s@%s", opts.User, opts.Host), remoteCmd)

	cmd := exec.CommandContext(ctx, "ssh", args...)
	cmd.Env = os.Environ()
	cmd.WaitDelay = 30 * time.Second
	return cmd.CombinedOutput()
}

func (r *Runner) execSCP(ctx context.Context, opts Options, src, dst string) error {
	args := r.baseArgs(opts)
	args = append(args, "-P", fmt.Sprintf("%d", opts.Port))
	args = append(args, src, fmt.Sprintf("%s@%s:%s", opts.User, opts.Host, dst))

	cmd := exec.CommandContext(ctx, "scp", args...)
	cmd.Env = os.Environ()
	cmd.WaitDelay = 30 * time.Second
	out, err := cmd.CombinedOutput()
	if err != nil && opts.LegacySCPFallback {
		r.logger.Info("SCP failed, retrying with legacy scp -O",
			slog.String("component", "ssh"),
			slog.String("output", strings.TrimSpace(string(out))))
		args = append([]string{"-O"}, args...)
		cmd = exec.CommandContext(ctx, "scp", args...)
		cmd.Env = os.Environ()
		cmd.WaitDelay = 30 * time.Second
		out, err = cmd.CombinedOutput()
	}
	if err != nil {
		return fmt.Errorf("scp failed: %w: %s", err, strings.TrimSpace(string(out)))
	}
	return nil
}

func (r *Runner) baseArgs(opts Options) []string {
	args := []string{
		"-o", "StrictHostKeyChecking=yes",
		"-o", "BatchMode=yes",
		"-o", "ConnectTimeout=" + fmt.Sprintf("%d", opts.Timeout),
	}
	if opts.Port != 0 && opts.Port != 22 {
		args = append(args, "-p", fmt.Sprintf("%d", opts.Port))
	}
	if opts.IdentityPath != "" {
		args = append(args, "-i", opts.IdentityPath)
	}
	if opts.HostKeyFingerprint != "" {
		args = append(args, "-o", "HostKeyAlias="+opts.Host)
		// TODO: enforce specific host key fingerprint via known_hosts or ssh-keyscan compare.
	}
	args = append(args, strings.Fields(opts.ExtraSSHOpts)...)
	return args
}

// RunCommand executes a remote command and returns its output.
func (r *Runner) RunCommand(ctx context.Context, opts Options, cmd string) ([]byte, error) {
	ctx, cancel := context.WithTimeout(ctx, time.Duration(opts.CMDTimeout)*time.Second)
	defer cancel()
	return r.execSSH(ctx, opts, cmd)
}

// CopyFile copies a local file to a remote path.
func (r *Runner) CopyFile(ctx context.Context, opts Options, src, dst string) error {
	ctx, cancel := context.WithTimeout(ctx, time.Duration(opts.CMDTimeout)*time.Second)
	defer cancel()
	return r.execSCP(ctx, opts, src, dst)
}

// ScanHostKey returns the SHA256 host key fingerprint for the target.
func (r *Runner) ScanHostKey(ctx context.Context, host string, port int) (string, error) {
	args := []string{"-H", "-p", fmt.Sprintf("%d", port), host}
	cmd := exec.CommandContext(ctx, "ssh-keyscan", args...)
	cmd.Env = os.Environ()
	out, err := cmd.CombinedOutput()
	if err != nil {
		return "", fmt.Errorf("ssh-keyscan failed: %w: %s", err, strings.TrimSpace(string(out)))
	}
	// TODO: parse and return SHA256 fingerprint.
	return strings.TrimSpace(string(out)), nil
}
