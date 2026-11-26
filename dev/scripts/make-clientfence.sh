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
#   dev/scripts/make-clientfence.sh --check    # fail if the word FOSSBilling survives anywhere
set -euo pipefail
cd "$(dirname "$0")/../.."

DEST="build/clientfence"
rm -rf "$DEST"
mkdir -p "$DEST"
cp -a src/. "$DEST/"

# Framework classes, longest first so that a prefix never eats a longer name.
python3 - <<'PYEOF'
import pathlib
import re

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
    # The two capabilities the fork adds to the registrar contract. They do not exist in
    # FOSSBilling, so only this build may name them; the methods themselves are in both.
    #
    # A missing interface is a fatal error at class declaration, which would take the whole
    # registrar down (register, renew, transfer, DNS) on a ClientFence older than the one that
    # added them — so this build declares them itself if the platform has not. Where ClientFence
    # provides them, these two lines do nothing.
    (
        "require_once __DIR__ . '/Osir/autoload.php';",
        """// ClientFence 1.0.62 added these two optional registrar capabilities. On an older release
// they are missing, and the adapter keeps working without them rather than failing to load.
if (!interface_exists('Registrar_TldPrices')) {
    interface Registrar_TldPrices
    {
        /** @param list<string> $tlds */
        public function getTldPrices(array $tlds = []): array;
    }
}
if (!interface_exists('Registrar_TransferStatus')) {
    interface Registrar_TransferStatus
    {
        public const PENDING = 'pending';
        public const COMPLETED = 'completed';
        public const FAILED = 'failed';

        public function getTransferStatus(Registrar_Domain $domain): array;
    }
}

require_once __DIR__ . '/Osir/autoload.php';""",
    ),
    (
        "class Registrar_Adapter_Osir extends Registrar_AdapterAbstract",
        "class Registrar_Adapter_Osir extends Registrar_AdapterAbstract implements Registrar_TldPrices, Registrar_TransferStatus",
    ),
    # This build has its own published repository.
    ("Osir-Inc/fossbilling-osir", "Osir-Inc/clientfence-osir"),
    # Plain mentions in our own strings, comments and documentation.
    ("FOSSBilling", "ClientFence"),
]
# The plugin's own namespace. Written "Osir\FossBilling" in code and "Osir\\FossBilling" inside a
# PHP string (the autoloader's prefix), so the number of backslashes is kept as it is found.
OWN_NAMESPACE = re.compile(r"(Osir\\+)FossBilling")
SUFFIXES = {".php", ".twig", ".json", ".md"}

changed = 0
for path in pathlib.Path("build/clientfence").rglob("*"):
    if not path.is_file() or path.suffix not in SUFFIXES:
        continue
    before = path.read_text(encoding="utf-8")
    after = before
    for old, new in MAP:
        after = after.replace(old, new)
    after = OWN_NAMESPACE.sub(r"\1ClientFence", after)
    if after != before:
        path.write_text(after, encoding="utf-8")
        changed += 1
print(f"rewrote {changed} files")
PYEOF

# The namespace rename is all-or-nothing: a file left behind would not load at all.
if grep -rqE "Osir\\\\+FossBilling" "$DEST" 2>/dev/null; then
  echo "The plugin's own namespace was only partly renamed; fix the mapping." >&2
  exit 1
fi
if ! grep -rq "namespace Osir\\\\ClientFence" "$DEST/library" 2>/dev/null; then
  echo "The plugin's own namespace was not renamed; fix the mapping." >&2
  exit 1
fi

if [[ "${1:-}" == "--check" ]]; then
  # Nothing in this build may say FOSSBilling, in any spelling: partners read these files and ask.
  # The one exception is the salt of the installation id, which is a stable constant: changing it
  # would change every idempotency key of an installation that upgrades.
  leftover=$(grep -rInE "fossbilling|Box_(App|Database|Log|Url)|Box\\\\Mod\\\\" "$DEST" \
      | grep -vF "'osir-fossbilling|'" || true)
  if [[ -n "$leftover" ]]; then
    echo "FOSSBilling-only names survive in the ClientFence build:" >&2
    echo "$leftover" | sed 's/^/  /' >&2
    exit 1
  fi
  echo "ClientFence build is clean of FOSSBilling-only names."
fi

echo "ClientFence build written to ${DEST}/"
