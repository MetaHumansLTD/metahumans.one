Graphify is a local knowledge-graph builder designed to run inside specific agent/IDE environments (Aider, Hermes, Trae, etc). It reads a folder, extracts structure and relationships, and writes outputs like:

- graphify-out/graph.html
- graphify-out/GRAPH_REPORT.md
- graphify-out/graph.json
- graphify-out/cache/

Operational implications:

- It reads files on disk. If secrets exist in a repo and are not excluded, they can be ingested into reports/graphs.
- It may invoke the configured coding assistant runtime (platform-specific) to extract relationships from docs/images; this can send content off-host depending on the assistant/provider.
- It is compute-heavy on large repos and can generate large artifacts.
- It should be paired with a repo-level .graphifyignore to exclude vendor/, node_modules/, build outputs, and secret directories.

Platform notes:

- Trae platform uses an always-on mechanism (AGENTS.md) rather than PreToolUse hooks.
- Codex platform requires multi_agent=true in ~/.codex/config.toml for parallel extraction.

This repository does not auto-run Graphify. If enabled, it should run on-demand per workspace/repo and store artifacts in a tenant-scoped location under /data/tenants/.

Installed helpers on metahumans.one:

- CLI: /usr/local/bin/graphify (Python 3.11 venv under /opt/graphify)
- Tenant-scoped wrapper: /usr/local/bin/mh-graphify

Tenant-scoped usage:

- mh-graphify "<tenant_id>" "<repo_path>" update
- mh-graphify "<tenant_id>" "<repo_path>" watch
- mh-graphify "<tenant_id>" "<repo_path>" query "your question"
