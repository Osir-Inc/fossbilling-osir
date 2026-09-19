#!/usr/bin/env bash
# Generates a throwaway dev CA and a server certificate for the TLS mock (api.osir.test).
# Output goes to dev/.certs (git-ignored). Safe to re-run; existing files are kept.
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p .certs
cd .certs
if [[ -f ca.pem && -f server.pem ]]; then
  echo "certs already present in dev/.certs"; exit 0
fi
umask 077
openssl req -x509 -newkey rsa:3072 -nodes -days 825 -subj "/CN=OSIR FOSSBilling dev CA" \
  -keyout ca-key.pem -out ca.pem 2>/dev/null
openssl req -newkey rsa:2048 -nodes -subj "/CN=api.osir.test" \
  -keyout server-key.pem -out server.csr 2>/dev/null
printf "subjectAltName=DNS:api.osir.test,DNS:redirect.osir.test\nextendedKeyUsage=serverAuth\n" > ext.cnf
openssl x509 -req -in server.csr -CA ca.pem -CAkey ca-key.pem -CAcreateserial -days 825 \
  -extfile ext.cnf -out server.pem 2>/dev/null
rm -f server.csr ext.cnf ca.srl
# Certificates are public; keys are read by nginx's master process (root) only.
chmod 644 ca.pem server.pem
chmod 600 ca-key.pem server-key.pem
echo "generated dev CA + server cert in dev/.certs"
