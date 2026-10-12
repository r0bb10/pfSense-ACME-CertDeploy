# CertDeploy

[![Release](https://img.shields.io/github/v/release/r0bb10/pfSense-ACME-CertDeploy)](https://github.com/r0bb10/pfSense-ACME-CertDeploy/releases/latest)
[![Build](https://github.com/r0bb10/pfSense-ACME-CertDeploy/actions/workflows/release.yml/badge.svg)](https://github.com/r0bb10/pfSense-ACME-CertDeploy/actions/workflows/release.yml)
[![Platform](https://img.shields.io/badge/platform-pfSense%20CE%202.8%20%2F%20FreeBSD%2015%20amd64-blue)](https://www.netgate.com/pfsense-plus-software)
[![License](https://img.shields.io/github/license/r0bb10/pfSense-ACME-CertDeploy)](LICENSE)

CertDeploy is a native pfSense package that deploys ACME certificates to remote SSH targets.

It watches certificates managed by the pfSense ACME package, detects certificate changes, and uploads selected certificate artifacts to configured targets. Deployments can run automatically after renewal or manually from the package UI.

## Requirements

- pfSense CE 2.8 based on FreeBSD 15 amd64 system
- The pfSense ACME package configured to issue certificates
- SSH access from pfSense to each target
- An SSH identity whose public key is present in the target user's `authorized_keys`

CertDeploy does not request or renew certificates. ACME remains responsible for issuance and renewal.

## How It Works

1. ACME handles the certificate.
2. CertDeploy detects the certificate fingerprint.
3. It verifies that the certificate and private key match.
4. It uploads selected artifacts through SSH/SCP.
5. Files are activated atomically on the target.
6. Optional validation and reload commands run after activation.
7. If validation or reload fails, CertDeploy restores the previous remote files.

## Install

Open the [latest release](https://github.com/r0bb10/pfSense-ACME-CertDeploy/releases/latest) and use the installation command published with its package assets.

Open **Services > Acme Deployer** after installation.

## Configuration

1. Create or import an SSH identity.
2. Add its public key to the target user's `authorized_keys`.
3. Create a target and select an ACME certificate.
4. Select the certificate artifacts and remote destination.
5. Configure optional validation and reload commands.
6. Enable automatic deployment if required.

By default, CertDeploy records the SSH host key on first connection and requires that key on later deployments. **Host Key Fingerprint** in Target Connection is read-only and remains blank for new targets until a key is recorded. **Accept Host Key Changes**, directly beneath it, accepts and records changed keys on every deployment while enabled. Use it only for intentional key changes; disable it afterward to pin the newly recorded key. Runtime state is authoritative, with existing configured pins preserved as a fallback when no runtime pin exists.

Reconciliation deploys outdated certificates for all enabled targets, including those with **Deploy on Renewal** disabled. The ACME monitor only deploys targets with **Deploy on Renewal** enabled. Package configuration sync applies settings and manages the daemon; it is not a remote certificate check.

**Deploy on Renewal** is in **Files**, directly above **Verify Files** at the end of the section. Connection and command timeouts can be overridden per target in **Advanced**; blank or zero uses the global defaults.

### File Verification

After a successful deployment and during reconciliation, CertDeploy compares each configured remote file against its current local ACME artifact over SSH, including extra destinations. **Verify Files** is enabled by default and can be disabled in **Files**. It uses the existing target connection and requires no TLS endpoint settings. The remote host needs `sha256sum`, `sha256`, or `openssl`; only hashes are returned, never private-key contents.

**In sync** means the latest SSH check matched all configured files. **Outdated** means a remote file differs or is missing; **Check failed** means the files could not be checked. Local file or target changes invalidate a previous match until checked again. **Last Verified** records the last successful file comparison and survives later failures. This checks files only, not running services.

Failed checks use the global retry count and delay. They never trigger a deployment, rollback, or change its outcome. The last-deployed fingerprint remains internal bookkeeping for renewal-triggered pushes, not proof of remote synchronization.

## Backup

Configuration backups include identities, private keys, and the currently recorded SSH host key fingerprints in an encrypted format. A passphrase is required to create and restore a backup.

## License

MIT. See [LICENSE](LICENSE).
