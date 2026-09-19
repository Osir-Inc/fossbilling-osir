# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/).

## [1.1.0] - 2026-09-19

### Removed
- **BREAKING:** sandbox support. OSIR offers no test environment, so the "Sandbox API key" setting is gone and only live keys
  (`osir_live_…`) are accepted. FOSSBilling's Test Mode is now refused: while it is on, the adapter sends nothing to
  OSIR and the log explains why, so Test Mode can never turn into live, paid operations by mistake.
  A sandbox key stored by 1.0.x stays masked and unused; idempotency keys are unchanged.

### Upgrading from 1.0.x
- If you used Test Mode: before turning it off, cancel or delete domain orders that were created while it was on
  and are still pending or failed. Activated again with Test Mode off, they would be real, charged registrations.
- A sandbox key saved in 1.0.x can no longer be edited in the admin panel (the field is gone). It is unused and
  stays masked; to delete it, remove `api_key_test` from the registrar's stored configuration.

## [1.0.1] - 2026-09-19

### Fixed
- Renewal safety: an order without an expiry date in FOSSBilling is no longer renewed. Its retry key had to fall
  back to the domain's expiry, which FOSSBilling's cron sync (a separate process) could move between a lost answer
  and the retry, so a retry could renew and charge a second time. Such an order is now refused with an explanation;
  checkout orders always have an expiry date.

## [1.0.0] - 2026-09-19 (tagged, not released)

### Added
- Registrar adapter for FOSSBilling 0.8.7+: availability, registration, transfer-in, renewal (including
  payment of registry auto-renewals during OSIR's grace period), nameservers, contacts, transfer lock,
  WHOIS privacy, transfer codes and expiry synchronisation.
- Sandbox (OTE) support through FOSSBilling's Test Mode, with separate live and sandbox keys.
- Idempotent registrations, renewals and transfers: safe retries without double charges.
- Optional per-year cost limit, and refusal of premium domains.
- API keys can live in FOSSBilling's `config.php` array (`'osir' => ['api_key' => …]`), which survives FOSSBilling
  rewriting the file; constants and environment variables also work.
- `osir-doctor`, a read-only diagnostics command.
- **OSIR import** admin module (`modules/Osir`, System → OSIR import):
  - TLDs: loads OSIR's catalog, prices each TLD from OSIR's own quote (fees included, first-year promotions
    ignored), applies your markup (percentage + fixed amount, rounded up) and creates or re-prices the
    FOSSBilling TLDs. Preview before saving; TLDs of other registrars are never touched.
  - Domains: lists the domains in the OSIR account and turns the chosen ones into active orders of a
    FOSSBilling client, with OSIR's expiry date, nameservers and registrant contact. Nothing is registered,
    renewed or charged; renewals then run through the normal adapter flow.
- Optional **OSIR client theme** (`osir-fossbilling-theme-<version>.zip`): storefront with domain search, OSIR design,
  self-hosted Geist fonts, WCAG AA contrast.
- Development stack (FOSSBilling + MariaDB + TLS mock of the OSIR API), unit tests, PHPStan (max + strict),
  end-to-end suite, CI and reproducible release packaging.
