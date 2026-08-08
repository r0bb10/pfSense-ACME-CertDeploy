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

By default, CertDeploy records the SSH host key on first connection and requires that key on later deployments. Targets that intentionally regenerate SSH host keys can enable **Accept Host Key Changes** in Advanced settings.

## Backup

Configuration backups include identities and private keys in an encrypted format. A passphrase is required to create and restore a backup.

## License

MIT. See [LICENSE](LICENSE).
