#!/bin/sh

set -eu

PORTNAME="pfSense-pkg-CertDeploy"
PORTVERSION="${PORTVERSION:-dev}"
ABI="FreeBSD:15:amd64"
PREFIX="/usr/local"
ROOT=$(cd -- "$(dirname -- "$0")" && pwd)
FILES="${ROOT}/files"
BUILD="${ROOT}/build"
STAGE="${BUILD}/stage"
OUTPUT="${BUILD}/pkg"
SRC="${ROOT}/src/certdeploy"
DIST="${ROOT}/dist"

clean() {
	rm -rf "${BUILD}"
}

json_escape() {
	awk '{ gsub(/\\/, "\\\\"); gsub(/\t/, "\\t"); gsub(/"/, "\\\""); printf "%s\\n", $0 }'
}

build_go() {
	if [ ! -d "${SRC}" ]; then
		echo "Missing Go source directory: ${SRC}" >&2
		exit 1
	fi

	mkdir -p "${DIST}"
	if [ "${CERTDEPLOY_USE_PREBUILT:-0}" = "1" ] && [ -x "${DIST}/certdeployd" ] && [ -x "${DIST}/certdeployctl" ]; then
		echo "Using explicitly supplied prebuilt Go binaries..."
		return
	fi
	rm -f "${DIST}/certdeployd" "${DIST}/certdeployctl"
	echo "Building Go binaries for FreeBSD amd64..."
	(
		cd "${SRC}"
		GOOS=freebsd GOARCH=amd64 CGO_ENABLED=0 go build \
			-buildvcs=false \
			-ldflags="-s -w -X main.version=${PORTVERSION}" \
			-o "${DIST}/certdeployd" ./cmd/certdeployd
		GOOS=freebsd GOARCH=amd64 CGO_ENABLED=0 go build \
			-buildvcs=false \
			-ldflags="-s -w -X main.version=${PORTVERSION}" \
			-o "${DIST}/certdeployctl" ./cmd/certdeployctl
	)
}

stage() {
	build_go

	rm -rf "${STAGE}"
	mkdir -p \
		"${STAGE}${PREFIX}/bin" \
		"${STAGE}${PREFIX}/etc/rc.d" \
		"${STAGE}${PREFIX}/pkg/certdeploy" \
		"${STAGE}${PREFIX}/sbin" \
		"${STAGE}${PREFIX}/www/certdeploy" \
		"${STAGE}${PREFIX}/www/shortcuts" \
		"${STAGE}${PREFIX}/share/${PORTNAME}" \
		"${STAGE}/etc/inc/priv" \
		"${STAGE}/var/db/certdeploy"

	install -m 0755 "${DIST}/certdeployd" "${STAGE}${PREFIX}/sbin/certdeployd"
	install -m 0755 "${DIST}/certdeployctl" "${STAGE}${PREFIX}/sbin/certdeployctl"
	install -m 0555 "${FILES}${PREFIX}/etc/rc.d/certdeploy" "${STAGE}${PREFIX}/etc/rc.d/certdeploy"
	install -m 0644 "${FILES}${PREFIX}/pkg/certdeploy.xml" "${STAGE}${PREFIX}/pkg/certdeploy.xml"
	install -m 0644 "${FILES}${PREFIX}/pkg/certdeploy.inc" "${STAGE}${PREFIX}/pkg/certdeploy.inc"
	install -m 0644 "${FILES}${PREFIX}/pkg/certdeploy/certdeploy_acme.inc" "${STAGE}${PREFIX}/pkg/certdeploy/certdeploy_acme.inc"
	install -m 0644 "${FILES}${PREFIX}/pkg/certdeploy/certdeploy_general.inc" "${STAGE}${PREFIX}/pkg/certdeploy/certdeploy_general.inc"
	install -m 0644 "${FILES}${PREFIX}/pkg/certdeploy/certdeploy_identities.inc" "${STAGE}${PREFIX}/pkg/certdeploy/certdeploy_identities.inc"
	install -m 0644 "${FILES}${PREFIX}/pkg/certdeploy/certdeploy_deployments.inc" "${STAGE}${PREFIX}/pkg/certdeploy/certdeploy_deployments.inc"
	install -m 0644 "${FILES}${PREFIX}/pkg/certdeploy/certdeploy_log.inc" "${STAGE}${PREFIX}/pkg/certdeploy/certdeploy_log.inc"
	install -m 0644 "${FILES}${PREFIX}/pkg/certdeploy/certdeploy_backup.inc" "${STAGE}${PREFIX}/pkg/certdeploy/certdeploy_backup.inc"
	install -m 0644 "${FILES}${PREFIX}/share/${PORTNAME}/info.xml" "${STAGE}${PREFIX}/share/${PORTNAME}/info.xml"
	install -m 0644 "${FILES}${PREFIX}/www/shortcuts/pkg_certdeploy.inc" "${STAGE}${PREFIX}/www/shortcuts/pkg_certdeploy.inc"
	install -m 0644 "${FILES}/etc/inc/priv/certdeploy.priv.inc" "${STAGE}/etc/inc/priv/certdeploy.priv.inc"

	for file in "${FILES}${PREFIX}/www/certdeploy/"*.php; do
		if [ -f "${file}" ]; then
			rel="${file#"${FILES}""${PREFIX}"/}"
			mkdir -p "${STAGE}${PREFIX}/$(dirname "${rel}")"
			install -m 0644 "${file}" "${STAGE}${PREFIX}/${rel}"
		fi
	done

	for file in \
		"${STAGE}${PREFIX}/pkg/certdeploy.xml" \
		"${STAGE}${PREFIX}/share/${PORTNAME}/info.xml"; do
		sed "s/%%PKGVERSION%%/${PORTVERSION}/g" "${file}" > "${file}.tmp"
		mv "${file}.tmp" "${file}"
	done
}

manifest() {
	post_install_script=$(sed "s/%%PORTNAME%%/${PORTNAME}/g" "${FILES}/pkg-install.in" | json_escape)
	pre_deinstall_script=$(sed "s/%%PORTNAME%%/${PORTNAME}/g" "${FILES}/pkg-deinstall.in" | json_escape)
	post_deinstall_script=$(sed "s/%%PORTNAME%%/${PORTNAME}/g" "${FILES}/pkg-post-deinstall.in" | json_escape)

	cat > "${BUILD}/+MANIFEST" <<EOF
name: "${PORTNAME}"
version: "${PORTVERSION}"
origin: "security/${PORTNAME}"
comment: "Certificate deployment service for pfSense"
maintainer: "noreply@github.com"
prefix: "${PREFIX}"
abi: "${ABI}"
desc: "Native pfSense package that deploys ACME certificates to remote hosts over SSH."
www: "https://github.com/r0bb10/pfSense-ACME-CertDeploy"
licenselogic: "single"
licenses: ["APACHE20"]
categories: ["security"]
scripts: {
  post-install: "${post_install_script}",
  pre-deinstall: "${pre_deinstall_script}",
  post-deinstall: "${post_deinstall_script}"
}
EOF

	sed "s|%%DATADIR%%|share/${PORTNAME}|g" "${ROOT}/pkg-plist" > "${BUILD}/plist"
}

package() {
	stage
	manifest
	mkdir -p "${OUTPUT}"
	pkg create -M "${BUILD}/+MANIFEST" -p "${BUILD}/plist" -r "${STAGE}" -o "${OUTPUT}"
	find "${OUTPUT}" -maxdepth 1 -type f -print
}

case "${1:-package}" in
	clean) clean ;;
	stage) stage ;;
	package) package ;;
	*) echo "Usage: $0 [package|stage|clean]" >&2; exit 2 ;;
esac
