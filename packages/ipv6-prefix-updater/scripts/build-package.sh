#!/bin/sh

set -eu

SCRIPT_DIR="$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)"
WORKSPACE_DIR="$(CDPATH='' cd -- "${SCRIPT_DIR}/.." && pwd)"
STAGED_PORT_DIR="${WORKSPACE_DIR}/build/port"
PORTS_DIR="${PFSENSE_PORTS_DIR:-}"
PORT_DESTINATION="${PORTS_DIR}/sysutils/pfSense-pkg-Ipv6PrefixUpdater"
RELEASE_DIR="${WORKSPACE_DIR}/build/release"
CREATED_PORT_DESTINATION='false'

cleanup() {
  if [ "${CREATED_PORT_DESTINATION}" = 'true' ] && [ -d "${PORT_DESTINATION}" ]; then
    rm -rf -- "${PORT_DESTINATION}"
  fi
}

main() {
  if [ "$(uname -s)" != 'FreeBSD' ]; then
    echo 'The final pfSense package must be built on FreeBSD.' >&2
    exit 1
  fi

  if [ -z "${PORTS_DIR}" ] || [ ! -f "${PORTS_DIR}/Mk/bsd.port.mk" ]; then
    echo 'Set PFSENSE_PORTS_DIR to a pfSense FreeBSD-ports checkout.' >&2
    exit 1
  fi

  if [ ! -f "${STAGED_PORT_DIR}/Makefile" ]; then
    echo 'Run npm run build before packaging so build/port is staged.' >&2
    exit 1
  fi

  if [ -e "${PORT_DESTINATION}" ]; then
    echo "The temporary port destination already exists: ${PORT_DESTINATION}" >&2
    exit 1
  fi

  trap cleanup EXIT HUP INT TERM

  mkdir -p -- "$(dirname -- "${PORT_DESTINATION}")"
  cp -R -- "${STAGED_PORT_DIR}" "${PORT_DESTINATION}"
  CREATED_PORT_DESTINATION='true'

  make -C "${PORT_DESTINATION}" package BATCH=yes DISABLE_VULNERABILITIES=yes

  artifact=''

  for candidate in "${PORT_DESTINATION}"/work/pkg/pfSense-pkg-Ipv6PrefixUpdater-*.pkg "${PORT_DESTINATION}"/work/pkg/pfSense-pkg-Ipv6PrefixUpdater-*.txz; do
    if [ -f "${candidate}" ]; then
      artifact="${candidate}"
      break
    fi
  done

  if [ -z "${artifact}" ]; then
    echo 'The FreeBSD ports build completed without a pfSense package artifact.' >&2
    exit 1
  fi

  mkdir -p -- "${RELEASE_DIR}"
  cp -- "${artifact}" "${RELEASE_DIR}/"

  artifact_name="$(basename -- "${artifact}")"
  stable_artifact_name='pfSense-pkg-Ipv6PrefixUpdater.pkg'

  cp -- "${RELEASE_DIR}/${artifact_name}" "${RELEASE_DIR}/${stable_artifact_name}"

  sha256 -q "${RELEASE_DIR}/${artifact_name}" > "${RELEASE_DIR}/${artifact_name}.sha256"
  sha256 -q "${RELEASE_DIR}/${stable_artifact_name}" > "${RELEASE_DIR}/${stable_artifact_name}.sha256"

  echo "Created versioned and stable release artifacts with SHA-256 checksums in ${RELEASE_DIR}."
}

main "$@"
