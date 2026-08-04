# Domain Registrar Service Runbook

## Selected Base

Use `getnamingo/registrar` as the primary foundation for the standalone PHP domain service.

Scaffold files created from this runbook:

- `README.md`
- `database/schema.sql`
- `src/Domain/Provider/`
- `src/Domain/Sync/`
- `src/Infrastructure/Providers/`
- `src/Jobs/`
- `config/sync-jobs.php`
- `northflank/`

Related repositories:

- `getnamingo/registrar`
  - Open-source registrar management system and the preferred base for this project.
  - Link: https://github.com/getnamingo/registrar
- `getnamingo/epp-client`
  - Underlying EPP capability for direct registry integrations such as `.co.za`.
  - Link: https://github.com/getnamingo/epp-client
- `getnamingo/whmcs-epp-registrar`
  - Useful reference for registrar-style workflows, sync behavior, and EPP actions.
  - Link: https://github.com/getnamingo/whmcs-epp-registrar

## Scope

The service is a standalone PHP domain registrar platform with:

- direct `.co.za` registry operations through EPP
- NetEarthOne operations through its reseller/API platform
- domain import and synchronization for domains already registered on both platforms
- client-facing domain control under `hub`
- admin and back-office control under `control`

The user is already onboarded with `.co.za` and NetEarthOne and already has working credentials, so implementation should focus on integration, sync, automation, and UI rather than registrar accreditation.

## Platform Model

### `hub`

Purpose: client control panel.

Primary features:

- search domain availability
- register domain
- renew domain
- transfer domain
- update nameservers
- update contacts where supported
- retrieve or request auth/EPP codes where supported
- view expiry dates, registrar, status, and renewal pricing
- show billing and renewal notices

### `control`

Purpose: internal administrative console.

Primary features:

- manage registrar credentials and certificates
- manage TLD pricing tables
- run imports and sync jobs
- inspect registrar logs and API responses
- view failed syncs and retry jobs
- approve manual corrections
- monitor renewal dates, transfers, balances, and status changes

## Integration Design

### 1. `.co.za`

Use direct EPP integration via the Namingo registrar stack.

Expected responsibilities:

- EPP login and session handling
- domain check, create, renew, transfer, info, update
- contact and nameserver updates where supported by registry policy
- poll message handling
- domain status sync
- expiry and renewal date sync

Implementation note:

- keep `.co.za` credentials, endpoint details, allowed IPs, and certificates isolated in environment variables and mounted secrets
- build a dedicated provider adapter/service layer for `.co.za` inside the registrar application

### 2. NetEarthOne

NetEarthOne should be integrated as a separate provider adapter. Do not force it through the `.co.za` EPP flow.

Expected responsibilities:

- domain listing and import
- pricing sync
- renewal date sync
- domain status sync
- nameserver sync
- contact and lock operations where the upstream API supports them

Implementation note:

- treat NetEarthOne as a separate upstream registrar provider
- normalize its responses into the same internal domain model used by `.co.za`

## Required Internal Domain Model

Store a unified record for each domain, regardless of upstream provider:

- domain name
- TLD
- provider (`coza`, `netearthone`)
- upstream account identifier
- upstream domain/order identifier
- registration date
- expiry date
- next renewal date
- auto-renew flag
- transfer status
- domain status list
- registrant/admin/tech/billing contact references
- nameservers
- auth code availability state
- last successful sync timestamp
- last sync error

## Synchronization Requirements

The module must sync:

- existing domains already registered on `.co.za`
- existing domains already registered on NetEarthOne
- renewal dates
- expiry dates
- TLD pricing
- domain statuses
- nameservers
- transfer states where applicable

### Initial Import Process

1. Connect to `.co.za` and pull the full active domain portfolio available to the current registrar credentials.
2. Connect to NetEarthOne and pull the full active domain portfolio available to the reseller credentials.
3. Normalize all imported domains into the internal schema.
4. Match against existing local records by FQDN and provider.
5. Create missing local records.
6. Update existing local records where upstream data is newer or authoritative.
7. Log all conflicts for manual review in `control`.

### Ongoing Sync Jobs

Use scheduled background jobs for:

- domain portfolio sync every 6 to 12 hours
- renewal and expiry sync daily
- pricing sync daily or on-demand
- transfer status sync every 1 to 4 hours
- failed job retry every 15 to 30 minutes

### Sync Conflict Rules

Treat the upstream registrar/registry as authoritative for:

- expiry date
- current status
- transfer state
- nameserver state after a confirmed upstream update

Treat local application data as authoritative for:

- internal customer mapping
- local billing flags
- local renewal reminders
- UI metadata and notes

## Pricing Sync

Pricing should be managed in two layers:

### Upstream base pricing

Pulled from:

- NetEarthOne pricing endpoints or exports
- `.co.za` pricing reference configured in `control` if no live pricing API is available

### Selling price

Managed locally in `control`:

- base price
- markup
- currency conversion
- promotional override
- grace or redemption pricing where needed

Recommended rule:

- sync upstream base pricing automatically
- calculate public prices locally
- keep a history table of price changes

## Renewal and Date Handling

Each imported or synced domain should keep:

- registration date
- registry expiry date
- billable renewal due date
- grace period end date if supported
- redemption window if supported

Recommended behavior:

- display expiry date to clients in `hub`
- calculate invoice timing locally
- sync true expiry dates from the provider on every date-sync run

## Northflank Hosting Layout

Deploy the project as two public apps under one shared platform setup:

Deployment source of truth:

- keep application code and environment definitions in the local project repository
- deploy to Northflank from the tracked codebase so local source remains authoritative
- all application deployments via Docker/GitHub must originate from GitHub repositories

Observed live Northflank pattern from the `metahumans` project:

- `metahumans-one`
  - Git-backed combined service built from `https://github.com/MetaHumansLTD/metahumans.one`
  - Dockerfile path: `/Dockerfile`
  - branch: `main`
- `cpu-transfer`
  - deployment service using external image `ubuntu:22.04`
- `mariadb-service`
  - deployment service using external image `mariadb:11.4.10`
  - internal-only TCP port `3306`
- `neo4j-service`
  - deployment service using external image `neo4j:5.26.21-community`
  - internal-only TCP ports `7474` and `7687`
- `qdrant-service`
  - deployment service using external image `qdrant/qdrant:v1.9.7`
  - internal-only TCP ports `6333` and `6334`

Registrar deployment model should follow the same shape:

- `hub` and `control`
  - Git-backed Docker builds from the registrar GitHub repository
- `worker`
  - Git-backed job/worker build from the same registrar GitHub repository
- `mariadb` and `redis`
  - internal deployment services using stable container images

Assumed GitHub and domain targets currently wired into the scaffold:

- GitHub repository:
  - `https://github.com/MetaHumansLTD/domain-registrars`
- `hub` domain:
  - `hub.metahumans.one`
- `control` domain:
  - `control.metahumans.one`

### `hub` service

Purpose:

- customer-facing portal

Northflank setup:

- App name: `hub`
- public web service
- custom domain for client panel
- connects to shared database
- connects to shared Redis/queue if background jobs are split out
- reads only the secrets required for customer-safe operations

### `control` service

Purpose:

- administrative portal

Northflank setup:

- App name: `control`
- public web service with restricted access
- protect behind strong authentication and, if possible, IP restriction or additional access controls
- connects to shared database
- can trigger sync jobs and view logs
- has access to provider credentials and operational secrets

### Shared services

Provision on Northflank:

- MariaDB or PostgreSQL database
- Redis for queues, caching, and job coordination
- optional worker service for scheduled syncs and imports

### Recommended deployment pattern

- `hub` handles customer interactions
- `control` handles admin workflows
- `worker` handles sync, import, pricing refresh, and renewal-date refresh jobs

## Northflank Environment and Secrets

Store separately for each upstream:

- `.co.za` username
- `.co.za` password
- `.co.za` EPP endpoint
- `.co.za` certificate paths or mounted secret files
- `.co.za` certificate passphrase if used
- NetEarthOne reseller ID
- NetEarthOne username if required
- NetEarthOne API key/password
- NetEarthOne API endpoint

Shared application secrets:

- app key
- database credentials
- Redis credentials
- mail settings
- queue settings
- cron or scheduler configuration

Important:

- keep provider credentials available to `control` and worker services
- do not expose registrar secrets directly to `hub`

## Suggested Implementation Process

### Phase 1 - Foundation

1. Deploy `getnamingo/registrar` locally.
2. Confirm database, queue, and authentication setup.
3. Create two deployment targets: `hub` and `control`.
4. Define the shared internal domain schema and provider abstraction layer.

### Phase 2 - `.co.za` Provider

1. Build the direct `.co.za` provider integration using the Namingo EPP stack.
2. Implement domain list import.
3. Implement domain info sync, status sync, nameserver sync, and date sync.
4. Test register, renew, and update flows against the live or test environment.

### Phase 3 - NetEarthOne Provider

1. Build a NetEarthOne provider adapter.
2. Implement portfolio import.
3. Implement pricing sync.
4. Implement renewal date and status sync.
5. Normalize all responses into the shared internal model.

### Phase 4 - Admin Control

1. Build `control` views for domains, pricing, sync logs, and failed jobs.
2. Add manual sync actions.
3. Add conflict review tools.
4. Add provider health checks.

### Phase 5 - Client Hub

1. Build `hub` views for domain listing, renewals, nameserver changes, and transfer status.
2. Show expiry dates and renewal pricing.
3. Add customer-triggered actions that create queued upstream jobs.

### Phase 6 - Automation

1. Add scheduled sync jobs.
2. Add daily pricing refresh.
3. Add renewal reminder generation.
4. Add alerting for sync failures.

### Phase 7 - Northflank Production

1. Create shared database and Redis resources.
2. Deploy `hub`.
3. Deploy `control`.
4. Deploy worker/scheduler service.
5. Load secrets and certificates into Northflank.
6. Verify public routing, login, queues, sync jobs, and provider connectivity.

## Minimum MVP

The minimum viable production scope should include:

- import existing `.co.za` domains
- import existing NetEarthOne domains
- sync expiry and renewal dates
- sync TLD pricing
- show domains in `hub`
- manage domains in `control`
- renew domains
- update nameservers
- run scheduled sync jobs

## Operational Notes

- upstream systems remain authoritative for live domain state
- local data must be designed for repeated sync and reconciliation
- all upstream write actions should be logged with request and response data
- failed upstream actions should be retriable from `control`
- certificate handling for `.co.za` should be treated as a deployment-critical dependency
