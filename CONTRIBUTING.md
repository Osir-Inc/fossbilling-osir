# Contributing

Thanks for helping. A few rules keep this adapter safe to run against people's money:

1. **Everything runs in Docker**: `make check` (unit tests, PHPStan max + strict, coding style) and
   `make up install e2e` (end-to-end against FOSSBilling and the OSIR mock). Both must pass.
2. **Money paths need tests first.** Changes to registration, renewal, transfer, idempotency keys or retries
   need a unit test that fails without the change, and an e2e scenario when FOSSBilling's behaviour is involved.
3. **Never weaken the security properties** listed in the README (TLS verification, no redirects, key
   handling, input validation, log scrubbing, client-safe errors) without an issue discussing why.
4. **No new runtime dependencies.** The adapter must run on a plain FOSSBilling install.
5. Keep the adapter layered (see `docs/ARCHITECTURE.md`): FOSSBilling types only in `Osir.php` and
   `FossBillingMapper`; transport concerns only in `Http/`.

Security issues: see [SECURITY.md](SECURITY.md), never a public issue.
