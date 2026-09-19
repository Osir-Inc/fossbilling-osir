#!/usr/bin/env bash
# Builds the release archive dist/osir-fossbilling-registrar-<version>.zip and dist/SHA256SUMS.
#
# The archive is extracted by users INTO THEIR FOSSBILLING ROOT, so it contains nothing but
# library/Registrar/Adapter/Osir.php, library/Registrar/Adapter/Osir/** and the companion import
# module modules/Osir/** (the README, licence and changelog go inside the adapter folder: a
# top-level README.md or LICENSE would overwrite FOSSBilling's own). The build is reproducible: sorted entries, fixed timestamps and modes, so
# the same commit yields the same SHA-256 with the same Python/zlib (CI pins the runner image).
set -euo pipefail
cd "$(dirname "$0")/../.."

# Only committed content is released (set ALLOW_DIRTY=1 for a local trial build).
if [[ "${ALLOW_DIRTY:-0}" != "1" ]] && git rev-parse --git-dir >/dev/null 2>&1 \
   && [[ -n "$(git status --porcelain -- src theme README.md LICENSE CHANGELOG.md SECURITY.md)" ]]; then
  echo "Uncommitted changes in released paths; commit them or set ALLOW_DIRTY=1." >&2; exit 1
fi

VERSION=$(sed -n "s/.*PLUGIN = '\([^']*\)'.*/\1/p" src/library/Registrar/Adapter/Osir/src/Version.php)
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]] || { echo "Bad version: $VERSION" >&2; exit 1; }
MODULE_VERSION=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["version"])' src/modules/Osir/manifest.json)
[[ "$MODULE_VERSION" == "$VERSION" ]] || { echo "modules/Osir/manifest.json version $MODULE_VERSION != Version::PLUGIN $VERSION" >&2; exit 1; }
if [[ -n "${GITHUB_REF_NAME:-}" && "${GITHUB_REF_TYPE:-}" == "tag" && "$GITHUB_REF_NAME" != "v$VERSION" ]]; then
  echo "Tag $GITHUB_REF_NAME does not match Version::PLUGIN $VERSION" >&2; exit 1
fi

mkdir -p dist
OUT="dist/osir-fossbilling-registrar-${VERSION}.zip"
python3 - "$OUT" <<'PY'
import os, sys, zipfile

out = sys.argv[1]
src = "src"
files = []
for top in ("library", "modules"):
    for base, dirs, names in os.walk(os.path.join(src, top)):
        dirs.sort()
        for n in sorted(names):
            files.append(os.path.join(base, n))
docs = {  # repository file -> path inside the archive
    "README.md": "library/Registrar/Adapter/Osir/README.md",
    "LICENSE": "library/Registrar/Adapter/Osir/LICENSE",
    "CHANGELOG.md": "library/Registrar/Adapter/Osir/CHANGELOG.md",
    "SECURITY.md": "library/Registrar/Adapter/Osir/SECURITY.md",
}
entries = sorted([(os.path.relpath(f, src), f) for f in files] + [(dst, srcf) for srcf, dst in docs.items()])
for arc, _ in entries:
    assert arc.startswith(("library/Registrar/Adapter/Osir", "modules/Osir/")), arc
    assert "/." not in arc and not arc.endswith((".bak", ".orig")), arc

with zipfile.ZipFile(out, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as z:
    for arc, path in entries:
        info = zipfile.ZipInfo(arc, date_time=(1980, 1, 1, 0, 0, 0))
        info.compress_type = zipfile.ZIP_DEFLATED
        info.external_attr = 0o100644 << 16
        info.create_system = 3
        with open(path, "rb") as fh:
            z.writestr(info, fh.read())
print(f"{out}: {len(entries)} files")
PY

# The optional client theme ships as its own archive, extracted into the FOSSBilling root as themes/osir/.
THEME_VERSION=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["version"])' theme/osir/manifest.json)
[[ "$THEME_VERSION" == "$VERSION" ]] || { echo "theme/osir/manifest.json version $THEME_VERSION != Version::PLUGIN $VERSION" >&2; exit 1; }
THEME_OUT="dist/osir-fossbilling-theme-${VERSION}.zip"
python3 - "$THEME_OUT" <<'PY'
import os, sys, zipfile

out = sys.argv[1]
entries = []
for base, dirs, names in os.walk("theme/osir"):
    dirs.sort()
    for n in sorted(names):
        if n.startswith(".") and n != ".gitkeep":
            continue
        path = os.path.join(base, n)
        entries.append(("themes/" + os.path.relpath(path, "theme"), path))
entries.append(("themes/osir/README.md", "theme/README.md"))
entries.append(("themes/osir/LICENSE", "LICENSE"))
entries.sort()
for arc, _ in entries:
    assert arc.startswith("themes/osir/") and "/." not in arc.replace("/.gitkeep", ""), arc

with zipfile.ZipFile(out, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as z:
    for arc, path in entries:
        info = zipfile.ZipInfo(arc, date_time=(1980, 1, 1, 0, 0, 0))
        info.compress_type = zipfile.ZIP_DEFLATED
        info.external_attr = 0o100644 << 16
        info.create_system = 3
        with open(path, "rb") as fh:
            z.writestr(info, fh.read())
print(f"{out}: {len(entries)} files")
PY

( cd dist && sha256sum "$(basename "$OUT")" "$(basename "$THEME_OUT")" > SHA256SUMS && cat SHA256SUMS )
