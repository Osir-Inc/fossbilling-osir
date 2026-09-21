# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/).

## [1.3.1] - 2026-09-21

### Changed
- **The premium rule from 1.3.0 is replaced.** It compared OSIR's price with `client_order.price`, which cannot
  carry that meaning: FOSSBilling writes that column once, at order creation, as the *registration* total and
  never updates it for renewals (which are invoiced from the TLD's renewal price), and it is the price *before*
  `client_order.discount` — so a free-with-hosting or promo-code order compared against money the customer
  never paid. A premium name could therefore renew, or be registered, for more than it sold for.
  The setting is now **"Allow premium domains within the cost limit"**: premium names are allowed only when a
  maximum cost per year is also set, and only while OSIR's price stays inside it — checked at checkout, at
  registration, and at renewal and transfer alike. The limit is a number the administrator controls, so it
  cannot drift out of step with anything.
- Premium names are also checked **before** they reach the cart: one priced above the limit is no longer shown
  as available. In 1.3.0 a customer could pay for a premium name that registration then refused, leaving a paid
  invoice and an order only an administrator could clear.
- The rule no longer depends on OSIR flagging a quote as `premium`. That field is not part of OSIR's published
  API contract, so a premium renewal that arrived unflagged would have passed unchecked; the cost limit now
  bounds every quote regardless.

### Upgrading from 1.3.0
- If you turned the 1.3.0 setting on, **set a maximum cost per year as well** — otherwise premium names are
  refused again. Check any premium name registered under 1.3.0: its renewal price may be above what you charge.

## [1.3.0] - 2026-09-21

**Superseded by 1.3.1: the rule below does not hold. Do not enable the setting on this version.**

### Added
- **Optional: premium domains that cost less than you charge.** A new registrar setting, off by default, allows a
  premium name when OSIR's price for it (fees included, for the period ordered) is at or below what the order
  charges. Registries price some names below the standard price of their TLD — numeric `.xyz` names cost well
  under a dollar — and refusing those was costing partners business for no benefit.
  - Renewals are checked the same way, against the renewal quote, so a name sold cheaply in its first year
    cannot renew above its selling price.
  - Fails closed: an order in a currency other than USD (the currency OSIR quotes in), an operation without an
    order, or a quote without a usable total is refused, as is any premium name while the setting is off.
  - The cost limit, if set, still applies on top.
  - At checkout the price is not known yet (there is no order until the customer buys), so with the setting on a
    premium name is offered at your standard TLD price and the decision is made when it is registered.

## [1.2.1] - 2026-09-20

### Added
- The OSIR theme now links to the DNS page: the domain management page in the client area has a **DNS** tab
  beside Nameservers. FOSSBilling's domain page cannot be extended by a module, so the link has to come from a
  theme; `theme/osir/html/mod_servicedomain_manage.html.twig` is FOSSBilling 0.8.7's own template with that one
  addition. On another theme, add the link yourself — the README has the snippet, and the DNS page works either
  way.

## [1.2.0] - 2026-09-20

### Added
- **DNS management in the client area.** Clients can list, add, edit and delete the DNS records of a domain they
  hold as an active order registered through OSIR, at `/osir/dns/<order id>`. No new product, order or invoice:
  the page hangs off the domain order itself.
  - The domain is always resolved from the client's own order (FOSSBilling's `findForClientById`), never from the
    request, and OSIR checks ownership again on every call.
  - The zone apex stays with OSIR: the SOA record and the domain's own NS records can be neither written,
    overwritten nor deleted — including by record id, which the page itself exposes. Sub-zone NS records and a
    CNAME on a host are allowed; a CNAME on the domain itself is refused, because it would break mail and web.
  - Records are validated before anything is sent (type, TTL, SRV port and weight, MX priority, and values that
    match their type), and every refusal is shown to the client rather than being logged to the browser console.
  - Adding a record carries a fresh `Idempotency-Key` per submission, so a retry after a timeout cannot add it
    twice while deleting a record and adding it back still works (a key reused across submissions would make
    OSIR replay its first answer for 30 days, and the record would silently never reappear).
  - The listing is cached for 20 seconds per order and dropped on every write, so one client reloading the page
    cannot exhaust the API key the whole installation shares.
  - The page warns when the domain does not use OSIR's nameservers, so records that cannot resolve are not
    edited silently. The nameservers are read from OSIR, never hardcoded.
  - Needs OSIR's `/v2/dns` endpoints; no new setting, and the existing API key is used.
- The module's admin page is unchanged; its name is now "OSIR domains", since it covers more than the import.

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
