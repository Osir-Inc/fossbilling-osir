# OSIR registrar for ClientFence

Register, transfer, renew and manage domains through [OSIR](https://osir.com) from
[ClientFence](https://www.clientfence.com), with DNS management in your client area.

> **This repository is generated.** The plugin is developed once and published for both ClientFence and
> FOSSBilling, whose registrar contracts are the same. The sources, issues and pull requests live at
> [Osir-Inc/fossbilling-osir](https://github.com/Osir-Inc/fossbilling-osir); every release there is rewritten
> for ClientFence's class names and pushed here. Please report anything you find in that repository — changes
> made here would be overwritten by the next release.

![The DNS page in the ClientFence client area](docs/screenshots/client-dns.png)

## What it does

- Registration, transfer-in and renewal, including renewals paid after the registry has already auto-renewed
- Nameservers, contacts, transfer lock, WHOIS privacy and transfer (EPP) codes
- Expiry synchronisation through ClientFence's cron
- **DNS in the client area:** your customers manage the records of their own domains — A, AAAA, CNAME, MX,
  TXT, SRV, CAA, NS, PTR, NAPTR. The zone apex stays with OSIR, so nobody takes their own zone offline
- **Import from OSIR:** create your TLDs from OSIR's catalogue with your markup, and turn domains you already
  hold at OSIR into orders without registering or charging anything
- Premium names are refused by default, and can optionally be allowed within a cost limit you set
- `osir-doctor`, a read-only diagnostics command

A lost connection, a retried cron run or a double click never registers or renews twice: every paid call
carries an idempotency key bound to the order.

## Install

1. Download `osir-clientfence-registrar-<version>.zip` and `SHA256SUMS` from the
   [latest release](https://github.com/Osir-Inc/clientfence-osir/releases/latest) and check the archive:
   `sha256sum -c SHA256SUMS --ignore-missing`.
2. Extract it into your ClientFence root. It adds only these paths:
   ```
   library/Registrar/Adapter/Osir.php
   library/Registrar/Adapter/Osir/…
   modules/Osir/…
   ```
3. In the admin panel, open **Domain Management → Registrars**, find **Osir** and click **Install**.
4. Add your OSIR API key (`osir_live_…`). Better still, keep it out of the database by adding
   `'osir' => ['api_key' => '…']` to `config.php`.
5. Activate the **OSIR domains** module under **Extensions** for the import and the client DNS page.
6. Check it: `php library/Registrar/Adapter/Osir/bin/osir-doctor.php`.

Tested against ClientFence 1.0.62. OSIR has no test environment, so ClientFence's Test Mode is refused rather
than quietly performing live, paid operations.

## Linking to the DNS page

ClientFence has no extension point for adding a tab to the domain page, so the page is reachable at
`/osir/dns/<order id>` but the stock theme does not link to it. To add the link, copy
`themes/palisade/html/mod_servicedomain_manage.html.twig` into your own theme and put this beside the other
tabs:

```twig
<a class="nav-link" href='{{ "osir/dns/#{order.id}"|url }}'>{{ 'DNS'|trans }}</a>
```

A theme upgrade overwrites that file, so re-apply it afterwards.

## Documentation and licence

Full documentation — configuration, pricing safety, premium domains, security notes — is in the
[main repository's README](https://github.com/Osir-Inc/fossbilling-osir#readme). Apache License 2.0.
