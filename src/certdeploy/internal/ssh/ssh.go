package ssh

import (
	"context"
	"crypto/sha256"
	"encoding/base64"
	"fmt"
	"log/slog"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
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
	Host                 string
	Port                 int
	User                 string
	IdentityPath         string
	HostKeyFingerprint   string
	HostKeyIgnoreChanges bool
	KnownHostsFile       string
	HostKeyAlgorithm     string
	Timeout              int
	CMDTimeout           int
	ExtraSSHOpts         string
	UseSFTP              bool
	LegacySCPFallback    bool
	// ControlSocket is the path to an OpenSSH control socket for connection
	// multiplexing. When set, ssh/scp invocations reuse that master connection.
	ControlSocket string
}

// HostKeyResult holds a scanned host key and its SHA256 fingerprint.
type HostKeyResult struct {
	Line        string
	Fingerprint string
	Algorithm   string
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
		r.logger.Debug("SCP failed, retrying with legacy scp -O")
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

// baseArgs returns ssh/scp common arguments. When HostKeyFingerprint is set or
// a known hosts file has been prepared, it points OpenSSH at that file.
func (r *Runner) baseArgs(opts Options) []string {
	timeout := opts.Timeout
	if timeout == 0 {
		timeout = r.cfg.DefaultSSHTimeout
	}
	args := []string{
		"-o", "BatchMode=yes",
		"-o", "ConnectTimeout=" + fmt.Sprintf("%d", timeout),
	}
	if opts.Port != 0 && opts.Port != 22 {
		args = append(args, "-p", fmt.Sprintf("%d", opts.Port))
	}
	if opts.IdentityPath != "" {
		args = append(args, "-i", opts.IdentityPath)
	}
	if opts.KnownHostsFile != "" {
		args = append(args,
			"-o", "StrictHostKeyChecking=yes",
			"-o", "UserKnownHostsFile="+opts.KnownHostsFile,
		)
		if opts.HostKeyAlgorithm != "" {
			args = append(args, "-o", "HostKeyAlgorithms="+allowedAlgorithms(opts.HostKeyAlgorithm))
		}
	}
	if opts.ControlSocket != "" {
		args = append(args,
			"-o", "ControlPath="+opts.ControlSocket,
			"-o", "ControlMaster=no",
		)
	}
	args = append(args, strings.Fields(opts.ExtraSSHOpts)...)
	return args
}

func allowedAlgorithms(algorithm string) string {
	switch algorithm {
	case "ssh-ed25519":
		return "ssh-ed25519"
	case "ecdsa-sha2-nistp256":
		return "ecdsa-sha2-nistp256"
	case "ecdsa-sha2-nistp384":
		return "ecdsa-sha2-nistp384"
	case "ecdsa-sha2-nistp521":
		return "ecdsa-sha2-nistp521"
	case "ssh-rsa":
		return "ssh-rsa,rsa-sha2-256,rsa-sha2-512"
	default:
		return hostKeyAlgorithms()
	}
}

// RunCommand executes a remote command and returns its output.
func (r *Runner) RunCommand(ctx context.Context, opts Options, cmd string) ([]byte, error) {
	ctx, cancel := context.WithTimeout(ctx, r.cmdTimeout(opts)*time.Second)
	defer cancel()
	return r.execSSH(ctx, opts, cmd)
}

// CopyFile copies a local file to a remote path.
func (r *Runner) CopyFile(ctx context.Context, opts Options, src, dst string) error {
	ctx, cancel := context.WithTimeout(ctx, r.cmdTimeout(opts)*time.Second)
	defer cancel()
	return r.execSCP(ctx, opts, src, dst)
}

func (r *Runner) cmdTimeout(opts Options) time.Duration {
	if opts.CMDTimeout > 0 {
		return time.Duration(opts.CMDTimeout)
	}
	return time.Duration(r.cfg.DefaultCMDTimeout)
}

// StartControlMaster opens a persistent OpenSSH control connection for
// multiplexing. It returns the control socket path and a cleanup function that
// must be called to close the master connection and remove the temp directory.
func (r *Runner) StartControlMaster(ctx context.Context, opts Options) (string, func(), error) {
	dir, err := os.MkdirTemp("", "certdeploy-ssh-*")
	if err != nil {
		return "", nil, fmt.Errorf("failed to create control socket directory: %w", err)
	}
	socket := filepath.Join(dir, "control.sock")

	args := r.baseArgs(opts)
	args = append(args,
		"-f", "-N", "-M",
		"-o", "ControlPath="+socket,
		"-o", "ControlPersist=yes",
		fmt.Sprintf("%s@%s", opts.User, opts.Host),
	)

	cmd := exec.CommandContext(ctx, "ssh", args...)
	cmd.Env = os.Environ()
	out, err := cmd.CombinedOutput()
	if err != nil {
		_ = os.RemoveAll(dir)
		return "", nil, fmt.Errorf("failed to start SSH control master: %w: %s", err, strings.TrimSpace(string(out)))
	}

	cleanup := func() {
		_ = exec.Command("ssh", "-S", socket, "-O", "exit", fmt.Sprintf("%s@%s", opts.User, opts.Host)).Run()
		_ = os.RemoveAll(dir)
	}
	return socket, cleanup, nil
}

// ScanAndVerifyHostKey scans the target host key, verifies it against the
// configured fingerprint unless ignore-changes is enabled, and returns a
// HostKeyResult with the accepted key line and fingerprint.
func (r *Runner) ScanAndVerifyHostKey(ctx context.Context, opts Options) (HostKeyResult, error) {
	result := HostKeyResult{}
	keys, err := r.scanHostKeys(ctx, opts.Host, opts.Port)
	if err != nil {
		return result, err
	}
	if len(keys) == 0 {
		return result, fmt.Errorf("no host keys returned by ssh-keyscan")
	}

	if opts.HostKeyFingerprint != "" && !opts.HostKeyIgnoreChanges {
		// Accept any scanned key whose fingerprint matches the configured one.
		for _, k := range keys {
			if k.Fingerprint == opts.HostKeyFingerprint {
				return k, nil
			}
		}
		return result, fmt.Errorf("host key fingerprint mismatch: none of the returned keys matched %s", opts.HostKeyFingerprint)
	}

	// No configured fingerprint (or ignore-changes): prefer ED25519, then ECDSA, then RSA.
	var selected HostKeyResult
	for _, k := range keys {
		if k.Algorithm == "ssh-ed25519" {
			selected = k
			break
		}
	}
	if selected.Fingerprint == "" {
		for _, k := range keys {
			if strings.HasPrefix(k.Algorithm, "ecdsa-sha2-") {
				selected = k
				break
			}
		}
	}
	if selected.Fingerprint == "" {
		for _, k := range keys {
			if k.Algorithm == "ssh-rsa" {
				selected = k
				break
			}
		}
	}
	if selected.Fingerprint == "" {
		selected = keys[0]
	}

	return selected, nil
}

// PrepareKnownHosts writes the accepted host key to a temporary known_hosts
// file and returns a closure that should be called to clean it up.
func (r *Runner) PrepareKnownHosts(opts Options, keyLine string) (string, func(), error) {
	dir := os.TempDir()
	f, err := os.CreateTemp(dir, "certdeploy-kh-*")
	if err != nil {
		return "", nil, fmt.Errorf("failed to create known_hosts temp file: %w", err)
	}
	if _, err := f.WriteString(keyLine + "\n"); err != nil {
		_ = f.Close()
		return "", nil, err
	}
	if err := f.Close(); err != nil {
		return "", nil, err
	}
	cleanup := func() {
		_ = os.Remove(f.Name())
	}
	return f.Name(), cleanup, nil
}

// hostKeyAlgorithms returns the supported host key algorithms in preference order.
func hostKeyAlgorithms() string {
	return "ssh-ed25519,ecdsa-sha2-nistp256,ecdsa-sha2-nistp384,ecdsa-sha2-nistp521,ssh-rsa,rsa-sha2-256,rsa-sha2-512"
}

func (r *Runner) scanHostKeys(ctx context.Context, host string, port int) ([]HostKeyResult, error) {
	args := []string{"-T", "10", "-p", fmt.Sprintf("%d", port), host}
	cmd := exec.CommandContext(ctx, "ssh-keyscan", args...)
	cmd.Env = os.Environ()
	out, err := cmd.CombinedOutput()
	if err != nil {
		return nil, fmt.Errorf("ssh-keyscan failed: %w: %s", err, strings.TrimSpace(string(out)))
	}

	var results []HostKeyResult
	for _, line := range strings.Split(string(out), "\n") {
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		fp, err := fingerprintFromKeyLine(line)
		if err != nil {
			r.logger.Debug("skipping host key line", slog.Any("error", err))
			continue
		}
		algo := keyAlgorithmFromLine(line)
		results = append(results, HostKeyResult{Line: line, Fingerprint: fp, Algorithm: algo})
	}
	return results, nil
}

func keyAlgorithmFromLine(line string) string {
	fields := strings.Fields(line)
	if len(fields) >= 2 {
		return fields[1]
	}
	return ""
}

func fingerprintFromKeyLine(line string) (string, error) {
	// ssh-keygen -lf - expects a known_hosts line on stdin.
	cmd := exec.Command("ssh-keygen", "-lf", "-")
	cmd.Stdin = strings.NewReader(line + "\n")
	out, err := cmd.Output()
	if err != nil {
		return "", fmt.Errorf("ssh-keygen -lf failed: %w", err)
	}
	// Output: 256 SHA256:xxxxxxx hostname (ED25519)
	fields := strings.Fields(string(out))
	if len(fields) < 2 {
		return "", fmt.Errorf("unexpected ssh-keygen output: %s", string(out))
	}
	return fields[1], nil
}

// FingerprintFromPublicKey computes the standard SSH SHA256 fingerprint for a
// public key line (e.g. the content of a .pub file).
func FingerprintFromPublicKey(pub string) (string, error) {
	parts := strings.Fields(pub)
	if len(parts) < 2 {
		return "", fmt.Errorf("invalid public key")
	}
	blob, err := base64.StdEncoding.DecodeString(parts[1])
	if err != nil {
		return "", err
	}
	sum := sha256.Sum256(blob)
	return "SHA256:" + base64.RawStdEncoding.EncodeToString(sum[:]), nil
}

var hostLineHostRE = regexp.MustCompile(`^([^\s]+)\s`)

// NormalizeHostKeyLine ensures the line uses the plain hostname so OpenSSH
// matches it regardless of how ssh-keyscan formatted the entry.
func NormalizeHostKeyLine(line, host string) string {
	return hostLineHostRE.ReplaceAllString(line, host+" ")
}

// KnownHostsFileFor prepares and returns the path to a known_hosts file
// containing the accepted key for opts.Host, plus a cleanup function and the
// key's algorithm.
func (r *Runner) KnownHostsFileFor(ctx context.Context, opts Options) (string, func(), string, string, error) {
	res, err := r.ScanAndVerifyHostKey(ctx, opts)
	if err != nil {
		return "", nil, "", "", err
	}
	line := NormalizeHostKeyLine(res.Line, opts.Host)
	path, cleanup, err := r.PrepareKnownHosts(opts, line)
	return path, cleanup, res.Fingerprint, res.Algorithm, err
}

// HostKeyAlgorithm returns the algorithm of the accepted host key for opts.Host.
func (r *Runner) HostKeyAlgorithm(ctx context.Context, opts Options) (string, error) {
	res, err := r.ScanAndVerifyHostKey(ctx, opts)
	if err != nil {
		return "", err
	}
	return res.Algorithm, nil
}

// TempDir returns the system temp directory for this runner.
func (r *Runner) TempDir() string {
	return filepath.Join(os.TempDir(), "certdeploy")
}
