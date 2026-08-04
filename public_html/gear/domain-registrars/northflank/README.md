# Northflank Layout

These service files are project-local deployment manifests for planning and versioning.

They are intentionally simple and should be translated into the exact Northflank UI or API configuration used for deployment.

## Services

- `hub.service.yaml`
  - public customer-facing GitHub-backed combined service
- `control.service.yaml`
  - public but restricted GitHub-backed admin service
- `worker.job.yaml`
  - GitHub-backed worker for imports and sync jobs
- `mariadb.service.yaml`
  - internal MariaDB deployment service
- `redis.service.yaml`
  - internal Redis deployment service

## Shared Resources

- managed database
- managed Redis
- mounted `.co.za` certificate secrets
- NetEarthOne API secrets

## Secret Split

- `hub` gets only application, database, and Redis secrets plus any safe public config
- `control` gets full registrar admin secrets
- `worker` gets full provider secrets because it performs sync and write operations

## Required Secret Sets Under `metahumans`

- `metahumans-coza-provider`
  - `COZA_HOST`
  - `COZA_PORT`
  - `COZA_USERNAME`
  - `COZA_PASSWORD`
  - `COZA_CLIENT_ID`
  - `COZA_LOGIN_OBJECT_URIS`
  - `COZA_LOGIN_EXTENSION_URIS`
- `metahumans-coza-certificates`
  - mounted `.pem` / `.p12` / CA files referenced by `COZA_CERT_PATH` and `COZA_CA_FILE`
- `metahumans-netearthone-provider`
  - `NETEARTHONE_API_BASE_URL`
  - `NETEARTHONE_AUTH_USER_ID`
  - `NETEARTHONE_API_KEY`
  - `NETEARTHONE_TIMEOUT`
  - `NETEARTHONE_DEFAULT_CUSTOMER_ID`
  - `NETEARTHONE_DEFAULT_INVOICE_OPTION`
  - `NETEARTHONE_PRICING_JSON`

Provider credentials must not be stored in `.env` files or app-managed settings.

Assumed current production targets:

- GitHub repo: `https://github.com/MetaHumansLTD/domain-registrars`
- hub domain: `hub.metahumans.one`
- control domain: `control.metahumans.one`
