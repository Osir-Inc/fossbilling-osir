#!/usr/bin/env bash
# Writes dev/.env with random local-only credentials (git-ignored). Keeps an existing file.
set -euo pipefail
cd "$(dirname "$0")/.."
if [[ -f .env ]]; then echo "dev/.env already exists"; exit 0; fi
rand() { openssl rand -hex 16; }
umask 077
cat > .env <<ENV
OSIRFB_DB_PASSWORD=$(rand)
OSIRFB_DB_ROOT_PASSWORD=$(rand)
OSIRFB_ADMIN_EMAIL=admin@example.test
OSIRFB_ADMIN_PASSWORD=Dev$(rand)
OSIRFB_HTTP_PORT=18480
OSIRFB_FOSSBILLING_VERSION=0.8.7
ENV
echo "wrote dev/.env"
