#!/bin/sh

set -eu

SCRIPT_DIR="$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)"
WORKSPACE_DIR="$(CDPATH='' cd -- "${SCRIPT_DIR}/.." && pwd)"
STAGED_PORT_DIR="${WORKSPACE_DIR}/build/port"
PORTS_DIR="${PFSENSE_PORTS_DIR:-}"
PORT_DESTINATION="${PORTS_DIR}/sysutils/pfSense-pkg-Ipv6PrefixUpdater"
RELEASE_DIR="${WORKSPACE_DIR}/build/release"
CREATED_PORT_DESTINATION='false'
PACKAGE_REBUILD_DIR=''

cleanup() {
  if [ -n "${PACKAGE_REBUILD_DIR}" ] && [ -d "${PACKAGE_REBUILD_DIR}" ]; then
    rm -rf -- "${PACKAGE_REBUILD_DIR}"
  fi

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

  if [ -f "${SCRIPT_DIR}/check-freebsd.sh" ]; then
    if ! (cd "${WORKSPACE_DIR}" && /bin/sh "${SCRIPT_DIR}/check-freebsd.sh"); then
      echo 'FreeBSD checks failed; the package was not built.' >&2
      exit 1
    fi
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

  PACKAGE_REBUILD_DIR="$(mktemp -d "${PORT_DESTINATION}/work/any-freebsd.XXXXXX")"

  mkdir -p -- "${PACKAGE_REBUILD_DIR}/root" "${PACKAGE_REBUILD_DIR}/output"
  tar -xf "${artifact}" -C "${PACKAGE_REBUILD_DIR}/root"
  mv -- "${PACKAGE_REBUILD_DIR}/root/+MANIFEST" "${PACKAGE_REBUILD_DIR}/manifest.ucl"
  rm -f -- "${PACKAGE_REBUILD_DIR}/root/+COMPACT_MANIFEST"

  sed -E '1s/^\{/\{"vital": true,/; s/("abi" *: *")[^"]*(")/\1FreeBSD:*\2/; s/("arch" *: *")[^"]*(")/\1freebsd:*\2/; s/^(abi *[:=] *"?)[^",]*("?)/\1FreeBSD:*\2/; s/^(arch *[:=] *"?)[^",]*("?)/\1freebsd:*\2/' \
    "${PACKAGE_REBUILD_DIR}/manifest.ucl" > "${PACKAGE_REBUILD_DIR}/manifest-any-freebsd.ucl"
  mv -- "${PACKAGE_REBUILD_DIR}/manifest-any-freebsd.ucl" "${PACKAGE_REBUILD_DIR}/manifest.ucl"

  pkg create \
    -M "${PACKAGE_REBUILD_DIR}/manifest.ucl" \
    -r "${PACKAGE_REBUILD_DIR}/root" \
    -o "${PACKAGE_REBUILD_DIR}/output"

  rebuilt_artifact=''

  for candidate in "${PACKAGE_REBUILD_DIR}"/output/*.pkg; do
    if [ -f "${candidate}" ]; then
      rebuilt_artifact="${candidate}"
      break
    fi
  done

  if [ -z "${rebuilt_artifact}" ]; then
    echo 'Rebuilding the package for any FreeBSD version produced no artifact.' >&2
    exit 1
  fi

  artifact_directory="$(dirname -- "${artifact}")"
  rebuilt_artifact_name="$(basename -- "${rebuilt_artifact}")"
  artifact="${artifact_directory}/${rebuilt_artifact_name}"

  mv -f -- "${rebuilt_artifact}" "${artifact}"

  artifact_abi="$(pkg query -F "${artifact}" '%q')"
  artifact_vital="$(pkg query -F "${artifact}" '%V')"

  if [ "${artifact_abi}" != 'FreeBSD:*' ]; then
    echo "The rebuilt package ABI is ${artifact_abi}; expected FreeBSD:*." >&2
    exit 1
  fi

  if [ "${artifact_vital}" != '1' ]; then
    echo "The rebuilt package vital flag is ${artifact_vital}; expected 1." >&2
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
