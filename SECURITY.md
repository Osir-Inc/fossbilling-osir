# Security policy

## Reporting a vulnerability

Please report vulnerabilities **privately** to OSIR through your OSIR support channel, marked "security".
Do not open a public issue for security problems.

Include the adapter version, the FOSSBilling and PHP versions, and steps to reproduce. You will get an
acknowledgement within three working days. Fixes are released as a new version with a security note in
the changelog; reporters are credited unless they prefer otherwise.

## Supported versions

Only the latest release receives security fixes.

## Scope

In scope: this adapter (`library/Registrar/Adapter/Osir.php`, `library/Registrar/Adapter/Osir/**`), the
OSIR import module (`modules/Osir/**`) and the release packaging. Out of scope: FOSSBilling itself (report to the FOSSBilling project), and the OSIR
platform. Report OSIR platform issues to OSIR directly.

## Security design

The adapter's security properties (TLS verification, no redirects, key handling, input validation, log
scrubbing, client-safe errors) are listed in the README under **Security**, and explained in
[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).
