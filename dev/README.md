# Development stack

Everything runs in Docker. No PHP, Composer or database is needed on the host.

| Service | What | Reachable from the host |
|---|---|---|
| `fossbilling` | FOSSBilling 0.8.9 (Apache + PHP 8.5) with the adapter bind-mounted read-only | `http://127.0.0.1:18480` only |
| `db` | MariaDB 11.4 | no |
| `mock-osir` / `mock-osir-app` | nginx (TLS, private dev CA) → PHP mock of the OSIR API (`dev/mock-osir/router.php`) | no |
| `e2e` | end-to-end runner (profile `e2e`, not started by `up`) | no |

Isolation, by design (the stack is meant to run on shared hosts):
- its own compose project (`osir-fossbilling-dev`) and an **internal** bridge network (no route to the host or
  the internet); only FOSSBilling is also attached to a second network, to publish its port;
- only FOSSBilling's port is published, and only on `127.0.0.1`;
- CPU, memory and process-count limits on every container;
- no host mounts outside this repository.

The adapter talks to the mock over **verified TLS**, with a dev CA generated into `dev/.certs`, exactly as
it talks to OSIR in production. Nothing in the test setup disables TLS verification.

## Usage

```sh
make env certs up install   # once: credentials, dev CA, start, non-interactive FOSSBilling install
make e2e                    # end-to-end suite (83 checks)
make test analyse cs        # unit tests, PHPStan, coding style (throwaway PHP 8.3 container)
make test-all-php           # unit tests on PHP 8.3, 8.4 and 8.5
make doctor                 # run osir-doctor inside the FOSSBilling container
make down                   # stop (data is kept in named volumes)
make clean                  # stop and delete volumes, certs, credentials
```

Generated, git-ignored files:
- `dev/.env`: random database and admin passwords, mode 600;
- `dev/.admin-api-token`: the FOSSBilling admin API token used by the e2e suite;
- `dev/.certs`: the dev CA and server certificate (the CA key is mode 600);
- `dev/.mock-state`: the mock's state and request log.

## The mock

`dev/mock-osir/router.php` implements the subset of the OSIR API the adapter uses. It follows the documented
contract, **including its quirks**: several error shapes, `available:false` on failed checks, the
idempotency semantics (scope, replay of stored 2xx/5xx, `REQUEST_IN_PROGRESS`), zone-less
UTC dates and cents.

Scenarios are driven by domain labels (`taken*`, `premium*`, `outage*`, `mine*`, `foreign*`) and by control endpoints:
`/__mock/reset`, `/__mock/state`, `/__mock/requests`, `/__mock/faults` (`502-after-commit`,
`timeout-after-commit`, `500`, `500-stored`, optionally repeated N times), `/__mock/set-status` and
`/__mock/auto-renew`. Domains labelled `foreign*` belong to "another OSIR customer" (ownership 403). The control endpoints can only be reached inside
the compose network.

The mock encodes our reading of the OSIR API. Before a release, the manual checklist in `docs/RELEASING.md`
is run against the real OSIR API (there is no OSIR test environment).

## The ClientFence build

ClientFence is a fork of FOSSBilling whose registrar contract is the same one: `Registrar_AdapterAbstract`
with the same methods, `Registrar_Domain`, `Model_*`. Only the framework classes around it are named
differently, so the plugin is generated rather than maintained twice:

```sh
dev/scripts/make-clientfence.sh           # -> build/clientfence/
dev/scripts/make-clientfence.sh --check   # fails if a FOSSBilling-only name survives
```

The mapping lives in that script and nowhere else. If ClientFence renames something, the script is the only
file to change. The plugin's own namespace (`Osir\FossBilling\`) is deliberately left alone, and the check
run fails if it was touched.

### Publishing it

ClientFence users get their own repository, [Osir-Inc/clientfence-osir](https://github.com/Osir-Inc/clientfence-osir),
which is generated and never edited by hand:

```sh
dev/scripts/build-release.sh                      # builds the three archives
dev/scripts/publish-clientfence-repo.sh v1.4.0    # commits, tags and releases the ClientFence build
```

It rebuilds the tree from the tag, refuses to push if anything personal or internal appears in it, commits as
`OSIR <noreply@osir.com>`, tags with the same version and attaches the same archive. `dev/clientfence-repo-README.md`
is that repository's README, and anything in `dev/clientfence-repo-assets/` (screenshots, for instance) is copied
in alongside it. The step is deliberately manual: automating it from CI would mean storing a token with write
access to a second repository.

What the rename covers: `Box\Mod\*`, `Box_App`, `Box_Database`, `Box_Log`, `Box_Url`, `FOSSBilling\*`,
the browser-side `FOSSBilling.api.*` helper, and plain mentions in our own strings and documentation.
