Multica is an agent lifecycle and task orchestration layer. In this stack it is intended to act as a control plane for agent tasks, not as the LLM provider itself.

Recommended placement:

- UI and configuration on metahumans.one (Hub)
- Runtimes on the machines that execute work (metahumans.one for web/code tasks; superhumans.one only when GPU-side jobs must run there)

This deployment adds:

- Multica settings UI: /gear/multica/settings.php
- Multica Hub entry point: /hub/agents/multi.php
- Self-host board (local): https://metahumans.one:8445/

Configuration is stored in /data/config/brain/multica.json and loaded via /.cue/multica.php.

Self-host server:

- Source checkout: /home/onemeta/public_html/gear/multica/server-src
- Docker Compose: docker-compose.metahumans.yml
- Server env: /data/config/brain/multica.selfhost.env
