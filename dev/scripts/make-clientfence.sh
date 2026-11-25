#!/usr/bin/env bash
# Generates the ClientFence build of the plugin from src/ into build/clientfence/.
#
# ClientFence (github.com/cPFence/clientfence) is a FOSSBilling fork whose registrar contract is
# the same one: Registrar_AdapterAbstract with the same methods, Registrar_Domain, Model_*. What
# differs is the names of the framework classes around it, so the port is a rename rather than a
# second implementation — and this script is the only place that knows the mapping, so the two
# builds cannot drift.
#
# Verified against ClientFence on 2026-11-25: every target class below exists there, and
# Order::findForClientById / assertOrderUsable / getOrderService keep their FOSSBilling signatures.
#
#   dev/scripts/make-clientfence.sh            # -> build/clientfence/
#   dev/scripts/make-clientfence.sh --check    # fail if any FOSSBilling-only name survives
set -euo pipefail
cd "$(dirname "$0")/../.."

DEST="build/clientfence"
rm -rf "$DEST"
mkdir -p "$DEST"
cp -a src/. "$DEST/"

# Framework classes, longest first so that a prefix never eats a longer name.
python3 - <<'PYEOF'
import pathlib

# Order matters: the longest, most specific name first.
MAP = [
    ("Box\\Mod\\", "ClientFence\\Mod\\"),
    ("FOSSBilling\\", "ClientFence\\"),
    ("Box_App", "ClientFence\\App"),
    ("Box_Database", "ClientFence\\Database"),
    ("Box_Log", "ClientFence\\Log"),
    ("Box_Url", "ClientFence\\Url"),
    # The browser-side API helper is named after the product.
    ("FOSSBilling.api.", "ClientFence.api."),
    # Plain mentions in our own strings, comments and documentation.
    ("FOSSBilling", "ClientFence"),
]
SUFFIXES = {".php", ".twig", ".json", ".md"}

changed = 0
for path in pathlib.Path("build/clientfence").rglob("*"):
    if not path.is_file() or path.suffix not in SUFFIXES:
        continue
    before = path.read_text(encoding="utf-8")
    after = before
    for old, new in MAP:
        after = after.replace(old, new)
    if after != before:
        path.write_text(after, encoding="utf-8")
        changed += 1
print(f"rewrote {changed} files")
PYEOF

# Our own namespace is ours; the rename above must not have touched it.
if grep -rq "namespace Osir\\\\ClientFence" "$DEST" 2>/dev/null; then
  echo "The plugin's own namespace was renamed; fix the mapping." >&2
  exit 1
fi

if [[ "${1:-}" == "--check" ]]; then
  leftover=$(grep -rlE "Box_(App|Database|Log|Url)|Box\\\\Mod\\\\|FOSSBilling" "$DEST" --include=*.php --include=*.twig || true)
  if [[ -n "$leftover" ]]; then
    echo "FOSSBilling-only names survive in the ClientFence build:" >&2
    echo "$leftover" >&2
    exit 1
  fi
  echo "ClientFence build is clean of FOSSBilling-only names."
fi

echo "ClientFence build written to ${DEST}/"
