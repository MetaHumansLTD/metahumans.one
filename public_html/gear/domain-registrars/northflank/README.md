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

Assumed current production targets:

- GitHub repo: `https://github.com/MetaHumansLTD/domain-registrars`
- hub domain: `hub.metahumans.one`
- control domain: `control.metahumans.one`
