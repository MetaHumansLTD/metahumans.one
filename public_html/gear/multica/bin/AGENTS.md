## graphify

Trigger: /graphify

Graphify outputs are stored tenant-scoped on metahumans.one under:
- /data/tenants/<tenant_id>/graphify/<repo>_*/

Rules:
- Before answering architecture or codebase questions, read GRAPH_REPORT.md for this repo from the tenant-scoped graphify folder
- If wiki/index.md exists for this repo, navigate it instead of reading raw files
- After modifying code files, update the graph with: `mh-graphify "<tenant_id>" "<repo_path>" update`

Commands:
- Build/update: `mh-graphify "<tenant_id>" "<repo_path>" update`
- Watch: `mh-graphify "<tenant_id>" "<repo_path>" watch`
- Query: `mh-graphify "<tenant_id>" "<repo_path>" query "your question"`
