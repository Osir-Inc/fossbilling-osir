# Architecture

## Layers

```
FOSSBilling (Servicedomain module)
   │  Registrar_Domain / Registrar_Domain_Contact / Registrar_Exception
   ▼
Registrar_Adapter_Osir            library/Registrar/Adapter/Osir.php
   │  thin: FOSSBilling types in, client-safe Registrar_Exception out (guard())
   ▼
FossBillingMapper                 Mapping/   FOSSBilling objects → value objects (DomainName, Nameservers,
   │                                         ContactData, OrderRef); registry state → Registrar_Domain
   ▼
RegistrarService                  Service/   use cases + money-safety rules
   ├─ RenewalPolicy / RenewalDecision        pure renewal decision table
   ├─ IdempotencyKeys                        key composition
   └─ Diagnostics                            read-only checks (osir-doctor)
   ▼
ApiClient + ApiRequest            Http/      transport: TLS, no redirects, size cap, retries, deadline
   ├─ ErrorClassifier                        OSIR's several error shapes → ApiErrorKind
   └─ RetryPolicy                            what may be repeated, and until when
   ▼
Symfony HttpClient (from FOSSBilling)  →  https://be.osir.com
```

The **OSIR import** module (`modules/Osir`, namespace `Box\Mod\Osir`) sits beside the Servicedomain module.
It reaches OSIR only through `Registrar_Adapter_Osir::importService()`, so it shares the adapter's key
handling, transport rules and logging:

```
modules/Osir  Api/Admin (permissions, input) → Service (FOSSBilling writes: TLDs, orders, services)
   ▼
ImportService                     Import/    read-only: catalog, per-TLD cost from a quote, domain list,
   ├─ CatalogTld / TldCost                   registrant contact
   ├─ RemoteDomain                           which domains may become orders
   └─ PriceRule / Rounding                   cost → selling price, integer cents, never below cost
```

Selling prices are always recomputed on the server from OSIR's cost and the markup; the browser only chooses
TLDs and markup. A preview and the import within the next hour use the same cached quote, so the price saved
is the price shown. Imported domains become normal `register` orders (so renewal invoicing and the adapter's
renewal flow apply), but their service row has no pending action, so re-activating the order can never
register the name.

Only the adapter, `FossBillingMapper` and the import module know FOSSBilling's classes. Everything below them is plain PHP
and is unit-tested without FOSSBilling. The tests still load FOSSBilling's *real* library classes from a
pinned, checksum-verified release; there are no hand-written stubs.

## Configuration

`SettingsResolver` builds an immutable `Settings` object, lazily on the first operation. FOSSBilling calls
`enableTestMode()` *after* constructing the adapter, so the mode cannot be known earlier.

- The API key comes from `OSIR_REGISTRAR_API_KEY[_TEST]` (a constant, then an environment variable), falling
  back to the stored setting. It is validated (`osir_(live|test)_…`), and its prefix must match the mode.
- The key is held in `Secret`, whose value lives in a static `WeakMap`, not in a property. No dump mechanism
  (`var_dump`, `print_r`, `var_export`, array casts, serialisation) can reach it. The adapter drops its raw
  copy of the settings keys once `Settings` exists.
- The base URL is not an admin setting. A server-level override must be https, with no path, credentials or query.
- The installation id (used in idempotency keys and contact ids) comes from FOSSBilling's `INSTANCE_ID`,
  which is stable across web and cron. `SYSTEM_URL` is not: its scheme follows the request.

## Transport (`ApiClient`)

Every request:
- verifies the peer and host (TLS 1.2+);
- follows no redirects, because a 3xx is a protocol error;
- sends `Accept-Encoding: identity` and aborts past 2 MB, so the size cap counts decoded bytes;
- carries `X-API-Key`, `X-Request-Id` (a UUID used as the reference shown to users) and a `User-Agent`;
- declares per endpoint whether the success body is OSIR's `{success, data}` envelope (`ApiRequest::unwrapped()`
  marks the bare DTO endpoints), instead of guessing from the body.

Errors are normalised by `ErrorClassifier`. Well-known error codes win over HTTP status, because OSIR returns
401 for both a bad API key and a bad transfer code, and some failures arrive inside a 2xx envelope.
A `Idempotent-Replay: true` header is recorded on successes *and* errors.

Retries (`RetryPolicy`):
- GET, or POST with an `Idempotency-Key`: retried after transport errors, 502/503/504 and 409
  `REQUEST_IN_PROGRESS`.
- Any request: retried after 429 (OSIR rejects it before the endpoint runs).
- One wall-clock deadline covers the whole call, including the time spent inside attempts: 90 s on the web,
  300 s on the CLI. Each attempt's timeout is capped to the time that is left.
- Transport failures are classified from the client's error text. Resets, empty replies and idle timeouts
  (after sending) come first and leave a write's outcome unknown. DNS, connect and TLS-handshake failures
  (before sending) have a known outcome.
- Keyed money POSTs get an idle timeout equal to the attempt timeout, because a slow registry may keep the
  connection silent. Other requests get 30 s.

## Money safety (`RegistrarService`)

**Principle:** "did this order already do it?" is answered by OSIR's idempotency store, not by guessing from
registry state. A keyed request whose answer is a *replayed* success proves that this very order did it.

**Idempotency keys** (`IdempotencyKeys`):
`fb:<installation>:<live|sandbox>:o<order>:<action>:<domain>[:<extra>][:a<n>]`.
- The environment is included because live and sandbox requests come from the same OSIR account, and a
  sandbox outcome must never answer a live order.
- Renewal keys include the *order's* expiry date, which FOSSBilling moves only after a successful renew
  action. The domain's own expiry is not used, because every sync overwrites it. For orders without an
  expiry (for example orders created through the admin API without a period), the domain expiry is the
  fallback, and the sync freeze below keeps that stable.
- There is no time component. OSIR keeps outcomes for 30 days, including a 5xx. When it replays a *stored*
  5xx, `sendMoneyCall` re-checks the registry: if the operation actually happened, that is success. Otherwise
  it retries under the next attempt number (`:a2`, …, up to 5; after that an administrator must contact OSIR).
  Each FOSSBilling retry walks this chain again from `:a1`.

**Registration.** If the name is available, the keyed request is sent. If the name shows as registered and
there is an order, the keyed request is sent anyway:
- a replayed success means this order registered it (a lost answer);
- a refusal means someone else did, and the order is left for an administrator, with a message that says
  whether the domain is in this account or elsewhere.

The creation-date heuristic (in this account, and created no earlier than the order) is used **only** when the
store cannot answer: after a stored 5xx, or `IDEMPOTENT_REPLAY_UNAVAILABLE`.

**Transfers** work the same way. A replayed success is this order's transfer. `TRANSFER_EXISTS` or
"already in the account" without a replay is refused for an administrator, never adopted.

**Renewals** (`RenewalPolicy`), rules in order:

| # | Registry state | Decision |
|---|---|---|
| 1 | redemption / pending delete | refuse: restore through OSIR support |
| 2 | deleted / transferred out | refuse |
| 3 | FOSSBilling has no expiry date | refuse: sync first |
| 4 | OSIR auto-renew grace period | pay it (always 1 year; a longer period is refused before anything is charged); the cost cap uses the higher of the renewal and registration quotes, because the grace is priced on the registration basis |
| 5 | OSIR expiry is already about N years past FOSSBilling's | already applied (a lost-answer retry) |
| 6 | otherwise | renew |

Rule 4 comes before rule 5 on purpose. In the grace period OSIR's expiry is *already* a year ahead because the
registry renewed on its own, and that must not be mistaken for our renewal. OSIR flags the grace period as soon
as its info endpoint sees the registry's `autoRenewPeriod`. After a stored 5xx, the re-check for a grace payment
is "no longer in grace", and for a renewal it is rule 5. A re-check that refuses propagates instead of rotating.

**Sync freeze.** While FOSSBilling has the order in `failed_renew`, `getDomainDetails()` does not copy a registry
expiry that is more than ~180 days past FOSSBilling's. The failed attempt may have gone through; keeping
FOSSBilling's date lets rule 5 recognise it on the retry. This closes "lost answer, then sync, then retry" for
orders without an expiry.

**Other safeguards:**
- Premium registrations are refused.
- The optional cost cap fails closed on any doubtful quote: a missing or non-positive total, the premium flag,
  a period mismatch, or a warning.
- Registrant contacts are validated strictly: the registrant becomes the legal holder of the domain, so an
  incomplete contact never reaches the registry.

## FOSSBilling integration notes

- **`getDomainDetails()`** never returns an auth code. FOSSBilling serialises the returned object into
  `service_domain.details`. Contacts are left as FOSSBilling has them.
- **Gone domains:** `getDomainDetails()` returns the domain unchanged (auth code cleared) and logs an error.
  This holds in every context: FOSSBilling's batch expiry sync is all-or-nothing, and cron can run through the
  web or the CLI. One failing domain would otherwise re-sync everything on every cron tick. The cost is that a
  manual sync of such a domain reports success, with the reason in the log.
- **`deleteDomain()`** is a logged no-op, because OSIR does not allow deletions through API keys. Refusing
  would make orders impossible to cancel.
- **Logging:** FOSSBilling 0.8.x passes a `Box_Log`, which runs `vsprintf()` over extra arguments. `SafeLogger`
  only ever passes one pre-formatted, sanitised string.

## Tests

| Layer | Where | What |
|---|---|---|
| Unit | `tests/Unit` | Every class. `ScriptedHttpClient` records exactly what would be sent to OSIR. FOSSBilling's real classes come from the pinned 0.8.7 release. |
| Static analysis | `phpstan.neon.dist` | Level max, plus strict rules, over `src` and `tests`. |
| End-to-end | `dev/e2e/run.php` | Real FOSSBilling 0.8.7 in Docker, driven through its admin API, against a stateful TLS mock of the OSIR API (83 checks). Fault injection covers lost answers (also on replays), fresh and stored 5xx, and registry auto-renew; `foreign*` domains answer with OSIR's ownership 403. The mock does not model OSIR's per-environment domain storage, and it charges a flat price. |
