#!/bin/bash
# Pre-start checkpoint entrypoint wrapper for registrar services (control, hub, worker).
#
# Runs immediately before the container CMD. Behavior:
#   1. If the env MH_SKIP_CHECKPOINT env var is set and non-empty -> skip checkpointing.
#   2. Ensure /data/config and /mysql mount points exist for the backup runner.
#   3. Invoke the app-level backup runner (gear/backups/run.php) for both the
#      mysql and data backup sets. If either set fails we still proceed to start
#      the service so a transient backup script failure does NOT prevent the
#      deploy. The error is logged to STDERR and the container continues.
#   4. Finally exec the passed-in CMD (apache2-foreground, php bin/worker, etc.)

set -u
set -o pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PUBLIC_ROOT="$(cd "${SCRIPT_DIR}/../../.." && pwd)"
RUN_PHP="${PUBLIC_ROOT}/gear/backups/run.php"
RUN_MYSQL_DUMPS="${PUBLIC_ROOT}/gear/backups/mysql-dumps.php"

log() {
    printf '[checkpoint][%s] %s\n' "$(date '+%Y-%m-%dT%H:%M:%S%z')" "$*"
}

run_php_script() {
    local script_path="$1"
    local set_id="${2:-}"
    local args=()
    if [ -n "$set_id" ]; then
        args=("$set_id")
    fi
    if [ ! -f "$script_path" ]; then
        log "SKIP script not found: $script_path"
        return 0
    fi
    if command -v php >/dev/null 2>&1; then
        log "RUN php ${script_path#${PUBLIC_ROOT}/} ${args[*]:-}"
        if php "$script_path" "${args[@]}"; then
            log "OK  php ${script_path#${PUBLIC_ROOT}/} ${args[*]:-}"
            return 0
        fi
        log "ERR php ${script_path#${PUBLIC_ROOT}/} ${args[*]:-} exited with code $?"
        return 0
    fi
    log "SKIP php binary not available on PATH (continuing to service start)"
    return 0
}

if [ -n "${MH_SKIP_CHECKPOINT:-}" ]; then
    log "SKIP checkpoint (MH_SKIP_CHECKPOINT=${MH_SKIP_CHECKPOINT})"
else
    mkdir -p /data/config /data/backups /mysql /var/www/html/var 2>/dev/null || true
    log "=== Pre-deploy app-level checkpoint starting ==="
    run_php_script "$RUN_MYSQL_DUMPS"
    run_php_script "$RUN_PHP" "mysql"
    run_php_script "$RUN_PHP" "data"
    log "=== Pre-deploy app-level checkpoint complete ==="
fi

log "EXEC $*"
exec "$@"
