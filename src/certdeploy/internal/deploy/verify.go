package deploy

import (
	"context"
	"crypto/sha256"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"

	"certdeploy/internal/ssh"
	"certdeploy/internal/state"
)

var remoteDigest = regexp.MustCompile(`(?i)\b[0-9a-f]{64}\b`)

// Verify compares every selected artifact at every destination over SSH. Only
// digests leave the target; private-key contents are never downloaded or logged.
// It never uploads files, runs deployment commands, or changes deployment state.
func (e *Engine) Verify(ctx context.Context, d Deployment) (state.FileVerification, string) {
	result := state.FileVerification{
		Status: "check_failed", LastCheckTime: time.Now().Unix(),
		Host: d.Host, Port: d.Port, IdentityID: d.IdentityID,
		Files: []state.VerifiedFile{},
	}
	fail := func(err error) (state.FileVerification, string) {
		result.Error = err.Error()
		return result, ""
	}
	noCertificate := d.CertificateName == "" || d.CertificateBase == ""
	noFiles := len(d.Artifacts) == 0 || d.Destination.Directory == ""
	if !d.Enabled || noCertificate || noFiles {
		result.Status = "not_configured"
		return result, ""
	}
	if err := validateArtifacts(d); err != nil {
		return fail(err)
	}
	destinations := append([]Destination{d.Destination}, d.ExtraDestinations...)
	for _, dest := range destinations {
		if dest.Directory == "" {
			continue
		}
		for _, art := range d.Artifacts {
			digest, err := localDigest(filepath.Join(d.CertificateBase, art.Source))
			if err != nil {
				return fail(fmt.Errorf("read local artifact %s: %w", art.Source, err))
			}
			result.Files = append(result.Files, state.VerifiedFile{
				Source: art.Source, Path: filepath.Join(dest.Directory, art.Filename), SHA256: digest,
			})
		}
	}
	pin, err := e.state.TrustedHostKey(d.ID, d.HostKeyFingerprint)
	if err != nil {
		return fail(fmt.Errorf("load trusted host key: %w", err))
	}
	opts := ssh.Options{
		Host: d.Host, Port: d.Port, User: d.User, IdentityPath: d.IdentityPath,
		HostKeyFingerprint: pin, HostKeyIgnoreChanges: d.HostKeyIgnoreChanges,
		Timeout: d.Timeout, CommandTimeout: d.CommandTimeout,
	}
	kh, cleanup, fingerprint, algorithm, err := e.ssh.KnownHostsFileFor(ctx, opts)
	if err != nil {
		return fail(fmt.Errorf("verify SSH host key: %w", err))
	}
	defer cleanup()
	opts.KnownHostsFile, opts.HostKeyAlgorithm = kh, algorithm
	if !d.DisableConnectionReuse {
		socket, closeMaster, err := e.ssh.StartControlMaster(ctx, opts)
		if err != nil {
			return fail(err)
		}
		defer closeMaster()
		opts.ControlSocket = socket
	}
	result.Status = "verified"
	for _, file := range result.Files {
		out, err := e.ssh.RunCommand(ctx, opts, remoteHashCommand(file.Path))
		if err != nil {
			result.Status = "check_failed"
			return fail(fmt.Errorf("check remote file %s: %w", file.Path, err))
		}
		digest := remoteDigest.FindString(string(out))
		if strings.TrimSpace(string(out)) == "CERTDEPLOY_MISSING" {
			result.Status = "mismatch"
			result.Error = "Remote file missing: " + file.Path
		} else if digest == "" {
			result.Status = "check_failed"
			return fail(fmt.Errorf("invalid hash response for %s", file.Path))
		} else if !strings.EqualFold(digest, file.SHA256) {
			result.Status = "mismatch"
			result.Error = "Remote file differs: " + file.Path
		}
	}
	// A renewal during the check must not produce a successful stale comparison.
	for _, file := range result.Files {
		digest, err := localDigest(filepath.Join(d.CertificateBase, file.Source))
		if err != nil || digest != file.SHA256 {
			result.Status = "check_failed"
			return fail(fmt.Errorf("local artifact changed during verification: %s", file.Source))
		}
	}
	result.LastCheckTime = time.Now().Unix()
	if result.Status == "verified" {
		result.LastVerifiedTime = result.LastCheckTime
	}
	return result, fingerprint
}

func localDigest(path string) (string, error) {
	data, err := os.ReadFile(path)
	if err != nil {
		return "", err
	}
	return fmt.Sprintf("%x", sha256.Sum256(data)), nil
}

func remoteHashCommand(path string) string {
	return "f=" + shellQuote(path) + "; " +
		"if [ ! -e \"$f\" ] && [ ! -L \"$f\" ]; then printf 'CERTDEPLOY_MISSING\\n'; " +
		"elif [ ! -f \"$f\" ] || [ ! -r \"$f\" ]; then echo 'File is not readable' >&2; exit 1; " +
		"elif command -v sha256sum >/dev/null 2>&1; then sha256sum < \"$f\"; " +
		"elif command -v sha256 >/dev/null 2>&1; then sha256 -q < \"$f\"; " +
		"elif command -v openssl >/dev/null 2>&1; then openssl dgst -sha256 < \"$f\"; " +
		"else echo 'No SHA256 utility available' >&2; exit 1; fi"
}
