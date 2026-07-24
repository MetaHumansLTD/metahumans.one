### ALWAYS ON MEMORY - DATABASE STRUCTURE ###
Here is a breakdown of what is required:
Memory and Knowledge Layer – Hybrid Brain
--------------------------------------------

To make Meta Humans™ behave like humans who remember and reason, memory is split across:

- Relational DB (MySQL):
  - Biometrics schema (auth/security): users, credentials, device enrollment, roles, privileged tables
  - Tenant DBs (DB-per-tenant): tenant-scoped application data (projects, assets, meetings, tasks, workflows)
  - Rule: /hub/* and /studio/* must use tenant DBs; biometrics is auth/security only

- Vector DB (Qdrant/Milvus):
  - Shared sharded collections (e.g., mh_shard_<N>) with mandatory tenant_id filters on every query and write
  - Conversation excerpts, user preferences, important facts
  - Uploaded documents and notes
  - Used for Retrieval‑Augmented Generation

- Knowledge Graph (GraphRAG):
  - Stores relationships between facts
  - Enforces business rules and constraints
  - Distinguishes similar but distinct concepts and domains

- **Block Storage**
  - `/mysql` – MySQL/MariaDB runtime data (Meta Humans SQL on port 3307)
  - `/vector` – Vector DB engines (Qdrant storage)
  - `/graph` – Graph DB / Knowledge graph (Neo4j storage)
  - `/data` – tenant artifacts, uploads, logs, workspaces, config

- **Object Storage**
  - Stores:
    - User‑uploaded documents, images, and videos
    - Generated media (personas clips, reels, 3D assets)
    - Large binaries and artifacts from StudioIDE™
  - Databases store only metadata and references, not blobs.
Memory (The Hippocampus): The "Always-On" System
This is the most critical upgrade. We replace the passive database with an Active Memory Agent (adapted from the Google architecture and running on superhumans.one by default; GPU hardware verified as H200 NVL).

- The Stack :
  1. Hermes 3 (Router) : Decides where to store information.
  2. MariaDB (Block Storage) : Stores hard facts (User ID, Preferences, Logs).
  3. Qdrant (Vector Storage) : Stores "vibe" and semantic associations (e.g., "User seemed sad yesterday").
  4. GraphRag (Knowledge Graph) : Connects concepts (e.g., "User works at X" -> "X is a tech company" -> "User likes tech news").
- The "Sleep" Cycle :
  - The daemon loop runs continuously on metahumans.one: `public_html/hub/memory/daemon.php` (systemd: `mh-memory-daemon.service`)
  - GPU inference runs on superhumans.one when `public_html/ai/hermes.json` targets it (default should target `https://superhumans.one/ai/chat.php`).
  - Deep consolidation triggers when a user scope is idle for > 60 minutes, with a 24h max cadence per scope, and is protected by global + per-tenant + per-scope budgets.

The databases:

Usage pattern:
MySQL cluster (MariaDB) for Meta Humans

MariaDB on port 3307 (block storage runtime) supports DB-per-tenant isolation:
- Each tenant has its own database/schema:
  - user tenants: tenant_user_<username>
  - persona tenants: tenant_persona_<persona>
- Applications must select the tenant database deterministically from tenant_id and use it for tenant-scoped reads/writes.
- The biometrics schema remains the auth/security boundary (login, device enrollment, re-auth, privileged tables) and is not used for general tenant-scoped application data.

Operational note:
- Tenant database provisioning and routing are driven by authoritative config state under /data/config (db_configs.json, tenant-contexts.json, database-context.json).
