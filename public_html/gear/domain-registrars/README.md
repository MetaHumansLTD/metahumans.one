# Domain Registrar Scaffold

This repository now contains a lightweight PHP application skeleton for a standalone domain registrar service built around `getnamingo/registrar`.

## Scaffold Layout

- `domain-runbook.md`
  - project decisions, rollout phases, and operational notes
- `database/shared-schema.sql`
  - shared registrar schema for providers, pricing, worker tasks, and sync logs
- `database/tenant-schema.sql`
  - tenant-owned schema for domains, contacts, and orders with zero-human ownership fields
- `src/Domain/Provider`
  - provider capability map and provider contracts
- `src/Domain/Sync`
  - sync context object shared by provider sync operations
- `src/Jobs`
  - queueable sync job stubs for imports, dates, pricing, and portfolio sync
- `config/sync-jobs.php`
  - job definitions, cadence, and queue assignment
- `northflank`
  - initial service layout for `hub`, `control`, and `worker`
- `Dockerfile`
  - GitHub-backed Docker build for `hub` and `control`
- `Dockerfile.worker`
  - GitHub-backed Docker build for `worker`
- `bin/worker`
  - worker entrypoint for scheduled and queued sync jobs
- `bootstrap/app.php`
  - application bootstrap and `.env` loading
- `public/index.php`
  - web entrypoint for `hub` and `control`
- `src/Services/PlatformTenantContextResolver.php`
  - maps the live platform session tenant, acting user, persona, and billing context into hub order payloads
- `src/Services/CueTenantSchemaProvisioner.php`
  - applies `tenant-schema.sql` across CUE-managed tenant DB configs discovered from control plane or `tenant-contexts.json`
- `public/assets/hub.css`
  - client-facing hub styling for search and registration flow
- `src/Presentation/Hub`
  - hub controller for domain search, results, and checkout
- `src/Presentation/Control`
  - administrative control UI for orders, domains, and task operations
- `bin/console`
  - CLI entrypoint for schema loading, app checks, and `.co.za` commands
- `bin/worker`
  - production worker entrypoint for queued and scheduled tasks
- `src/Infrastructure/Epp`
  - XML-over-TLS EPP client foundation for direct registry integrations
- `config/pricing/coza.sample.json`
  - local sample price reference for `.co.za`

## Intended Flow

1. `hub` serves the customer-facing domain portal.
2. `control` serves the administrative panel and sync tooling.
3. `worker` runs imports, reconciliation, pricing refreshes, and date sync jobs.
4. Both `.co.za` and NetEarthOne plug into a shared provider abstraction.

## Local Setup

1. Copy `.env.example` to `.env`.
2. Update the shared and tenant database settings, `.co.za` credentials, and NetEarthOne API credentials when you want `.com` style flows enabled.
3. Run `composer install`.
4. Load the schema:
   - `php bin/console db:schema:load`
   - or, when running inside CUE with a real control plane and tenant map:
     - `php bin/console db:schema:load:shared`
     - `php bin/console db:schema:provision:cue`
5. Check the app:
   - `php bin/console app:doctor`
6. Check `.co.za` connectivity:
   - `php bin/console coza:hello`
7. Run the hub locally:
   - `php -S 127.0.0.1:8080 -t public`
8. Run the worker:
   - `php bin/console worker:run`

To run control locally, set:

- `APP_ROLE=control`
- `CONTROL_USERNAME`
- `CONTROL_PASSWORD`

## Northflank Deployment Model

This scaffold now follows the live pattern observed in the `metahumans` Northflank project:

- application services are Git-backed Docker builds from GitHub repositories
- stateful infrastructure uses internal deployment services based on container images

Recommended registrar layout:

- `registrar-hub`
  - GitHub-backed combined service using `/Dockerfile`
- `registrar-control`
  - GitHub-backed combined service using `/Dockerfile`
- `registrar-worker`
  - GitHub-backed worker/job service using `/Dockerfile.worker`
- `registrar-mariadb`
  - optional internal shared registrar database using `mariadb:11.4.10`
- `registrar-redis`
  - internal deployment service using `redis:7.4-alpine`

This matches your rule that all application deployments via Docker/GitHub must come from GitHub repositories.

Assumed production targets currently wired into the manifests:

- GitHub repo: `https://github.com/MetaHumansLTD/domain-registrars`
- hub domain: `hub.metahumans.one`
- control domain: `control.metahumans.one`

## Current `.co.za` Coverage

The first real `.co.za` provider implementation now includes:

- TLS EPP connection setup
- EPP hello and login/logout flow
- domain availability check
- domain info lookup
- domain create
- domain renew
- nameserver updates
- local JSON-backed pricing reference sync

The first NetEarthOne provider implementation now includes:

- LogicBoxes-compatible availability checks
- domain registration
- domain detail and date sync by domain name
- renewals
- nameserver updates
- local JSON-backed pricing reference sync

## Current Hub UI

The hub client area now includes:

- domain search landing page
- registrar-style search results
- live provider-backed availability lookup for `.za` and NetEarthOne-backed search flows when configured
- registration checkout forms for both `.co.za` and NetEarthOne
- persistent customer, domain, and order saves
- draft or queued-live-submit mode controlled by `HUB_ALLOW_LIVE_REGISTRATION`

## Current Control UI

The control area now includes:

- dashboard with domain, order, and task counts
- recent orders, domains, and worker task visibility
- manual queue actions for pricing sync, date sync, portfolio sync, and retry jobs
- optional HTTP basic auth via `CONTROL_USERNAME` and `CONTROL_PASSWORD`

## Current Database Split

The app now separates data into two roles:

- shared registrar DB
  - provider accounts
  - pricing snapshots
  - worker tasks
  - sync and provider command logs
- tenant DB
  - customers
  - contacts
  - domains
  - domain statuses and nameservers
  - customer orders

The shared production target is intended to be the CUE-managed config `db_domain_registrars_shared`, currently pointing at `domainname_controller`.

When the app is hosted inside the Meta Humans platform, set `CUE_BOOTSTRAP_PATH` so the registrar can reuse the live CUE session, tenant routing, and control-plane DB resolution instead of local fallback inference.

Tenant-owned rows now carry:

- `tenant_id`
- `owner_type`
- `owner_id`
- acting user / persona context
- billing tenant + billing mode

This keeps company, user, and persona ownership aligned with the zero-human company model without duplicating the platform company registry in the registrar app.

Important limitation:

- standard EPP does not provide a universal "list all my domains" command, so full portfolio import still needs either registry-specific support, seeded known domains, or an upstream export path

## Next Build Step

Use this scaffold as the base for:

- wiring persistence and repositories into the schema
- implementing NetEarthOne against its live API
- binding job handlers to the real queue system
- connecting the Northflank services to shared secrets, database, and Redis
