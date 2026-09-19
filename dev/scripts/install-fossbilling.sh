#!/usr/bin/env bash
# Non-interactive FOSSBilling install into the dev stack, then:
#   - creates an admin API token (for the e2e suite, which drives FOSSBilling's admin API),
#   - pins the adapter's dev overrides as PHP constants in config.php (constants, not env
#     vars, because FOSSBilling's cron runs without the container environment).
set -euo pipefail
cd "$(dirname "$0")/.."
set -a; source .env; set +a
BASE="http://127.0.0.1:${OSIRFB_HTTP_PORT}"
DC="docker compose"

if $DC exec -T fossbilling test -f /var/www/html/config.php; then
  echo "FOSSBilling already installed"
else
  work=$(mktemp -d); trap 'rm -rf "$work"' EXIT
  jar="$work/cookies"; out="$work/response.html"
  # Secrets go through files (curl's @file syntax), never through the process list.
  umask 077
  printf '%s' "$OSIRFB_DB_PASSWORD" > "$work/dbpw"
  printf '%s' "$OSIRFB_ADMIN_PASSWORD" > "$work/adminpw"
  curl -fsS -c "$jar" -b "$jar" "$BASE/install/install.php" >/dev/null
  code=$(curl -sS -o "$out" -w '%{http_code}' -c "$jar" -b "$jar" \
    -X POST "$BASE/install/install.php?a=install" \
    --data-urlencode "database_hostname=db" \
    --data-urlencode "database_port=3306" \
    --data-urlencode "database_name=fossbilling" \
    --data-urlencode "database_username=fossbilling" \
    --data-urlencode "database_password@$work/dbpw" \
    --data-urlencode "admin_name=Dev Admin" \
    --data-urlencode "admin_email=${OSIRFB_ADMIN_EMAIL}" \
    --data-urlencode "admin_password@$work/adminpw" \
    --data-urlencode "currency_code=USD" \
    --data-urlencode "system_url=${BASE}/")
  if [[ "$code" != "200" ]]; then
    echo "install failed (HTTP $code):"; sed -e 's/<[^>]*>//g' "$out" | grep -v '^\s*$' | tail -20; exit 1
  fi
  echo "FOSSBilling installed"
fi

# Admin API token (32 hex chars), stored so the e2e suite can use HTTP basic auth admin:<token>.
TOKEN=$(openssl rand -hex 16)
# MYSQL_PWD is passed by name (-e VAR), so the password never appears in the host's process list.
MYSQL_PWD="$OSIRFB_DB_PASSWORD" $DC exec -T -e MYSQL_PWD db mariadb -ufossbilling fossbilling \
  -e "UPDATE admin SET api_token='${TOKEN}' WHERE email='${OSIRFB_ADMIN_EMAIL}';"
umask 077
echo "$TOKEN" > .admin-api-token
echo "admin API token written to dev/.admin-api-token"

# Dev-only adapter overrides as constants at the top of config.php (idempotent).
# config.php is included more than once per request, so every define() must be guarded.
$DC exec -T fossbilling php -r '
$f = "/var/www/html/config.php";
$c = file_get_contents($f);
if (!str_contains($c, "OSIR_REGISTRAR_API_URL")) {
    $inject = "<?php\n// --- OSIR adapter dev overrides (dev stack only) ---\n"
        . "defined(\"OSIR_REGISTRAR_API_URL\") || define(\"OSIR_REGISTRAR_API_URL\", \"https://api.osir.test\");\n"
        . "defined(\"OSIR_REGISTRAR_CA_FILE\") || define(\"OSIR_REGISTRAR_CA_FILE\", \"/certs/ca.pem\");\n";
    $c = preg_replace("/^<\?php\s*/", $inject, $c, 1);
    file_put_contents($f, $c);
    echo "config.php: dev overrides added\n";
} else { echo "config.php: dev overrides already present\n"; }
'
