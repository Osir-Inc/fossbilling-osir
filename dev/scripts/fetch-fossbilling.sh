#!/usr/bin/env bash
# Downloads a pinned FOSSBilling release into build/fossbilling-<version> and verifies its
# SHA-256 against the digest published by GitHub. Unit tests load FOSSBilling's real
# Registrar_* / FOSSBilling\* library classes from there instead of hand-written stubs.
set -euo pipefail
cd "$(dirname "$0")/../.."

VERSION="${1:-0.8.9}"
declare -A SHA256=(
  [0.8.7]=2e4ac8e389eec8cea17d03761a75cd6a2a2d7d6d4f4b79aca46a34c6e7910362
  [0.8.9]=f0b1ac0b59b119c3ccccebd4318a5a6e8e5fcb962dec55b272d42372c9e3dbd4
)
DEST="build/fossbilling-${VERSION}"
if [[ -f "${DEST}/library/Registrar/AdapterAbstract.php" ]]; then
  echo "FOSSBilling ${VERSION} already present in ${DEST}"; exit 0
fi
expected="${SHA256[$VERSION]:-}"
if [[ -z "$expected" ]]; then
  echo "No pinned checksum for FOSSBilling ${VERSION}; add it to this script first." >&2; exit 1
fi

mkdir -p build
zip="build/FOSSBilling-${VERSION}.zip"
# Retried: GitHub's download host answers 504 often enough to fail a release build on its own.
curl -fsSL --retry 5 --retry-delay 3 --retry-all-errors --connect-timeout 20 --max-time 300 \
    -o "$zip" "https://github.com/FOSSBilling/FOSSBilling/releases/download/${VERSION}/FOSSBilling-${VERSION}.zip"
actual=$(sha256sum "$zip" | cut -d' ' -f1)
if [[ "$actual" != "$expected" ]]; then
  echo "Checksum mismatch for $zip: expected $expected, got $actual" >&2; rm -f "$zip"; exit 1
fi
rm -rf "$DEST" && mkdir -p "$DEST"
python3 -m zipfile -e "$zip" "$DEST"   # python: no unzip dependency
rm -f "$zip"
echo "FOSSBilling ${VERSION} extracted to ${DEST}"
