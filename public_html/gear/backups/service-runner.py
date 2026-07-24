#!/usr/bin/env python3
import json
import os
import subprocess
import time
from pathlib import Path


def backup_root() -> Path:
    if Path("/backup").is_dir():
        return Path("/backup/backups")
    return Path("/backups")


def state_dir() -> Path:
    return backup_root() / ".state"


def allowlist():
    return {
        "mariadb": "mariadb.service",
        "redis": "redis.service",
    }


def run(cmd):
    p = subprocess.run(cmd, capture_output=True, text=True)
    return {
        "exit_code": int(p.returncode),
        "stdout": p.stdout or "",
        "stderr": p.stderr or "",
    }


def write_services_snapshot(dest: Path):
    services = allowlist()
    out = {}
    for key, unit in services.items():
        active = run(["systemctl", "is-active", unit])
        enabled = run(["systemctl", "is-enabled", unit])
        out[key] = {
            "unit": unit,
            "active": (active["stdout"].strip() or active["stderr"].strip() or "unknown"),
            "enabled": (enabled["stdout"].strip() or enabled["stderr"].strip() or "unknown"),
            "checked_at": time.strftime("%Y-%m-%dT%H:%M:%S%z"),
        }
    dest.write_text(json.dumps(out, indent=4), encoding="utf-8")


def main():
    sd = state_dir()
    queue_dir = sd / "service-actions"
    results_dir = queue_dir / "results"
    queue_dir.mkdir(parents=True, exist_ok=True)
    results_dir.mkdir(parents=True, exist_ok=True)

    services = allowlist()
    allowed_actions = {"start", "stop", "restart", "status"}

    files = sorted([p for p in queue_dir.glob("*.json") if p.is_file()])
    processed = 0
    for job_path in files[:10]:
        job_id = job_path.stem
        try:
            job = json.loads(job_path.read_text(encoding="utf-8"))
        except Exception:
            job_path.unlink(missing_ok=True)
            continue

        service_key = str(job.get("service", ""))
        action = str(job.get("action", ""))
        unit = services.get(service_key)

        result = {
            "id": job_id,
            "service": service_key,
            "unit": unit,
            "action": action,
            "requested_at": job.get("requested_at"),
            "requested_by": job.get("requested_by"),
            "ran_at": time.strftime("%Y-%m-%dT%H:%M:%S%z"),
            "ok": False,
        }

        if not unit or action not in allowed_actions:
            result["error"] = "Forbidden"
        else:
            if action == "status":
                res = run(["systemctl", "status", "--no-pager", unit])
            else:
                res = run(["systemctl", action, unit])

            result["exit_code"] = res["exit_code"]
            result["stdout"] = res["stdout"]
            result["stderr"] = res["stderr"]
            result["ok"] = (res["exit_code"] == 0)

            active = run(["systemctl", "is-active", unit])
            enabled = run(["systemctl", "is-enabled", unit])
            result["active"] = (active["stdout"].strip() or active["stderr"].strip() or "unknown")
            result["enabled"] = (enabled["stdout"].strip() or enabled["stderr"].strip() or "unknown")

        (results_dir / f"{job_id}.json").write_text(json.dumps(result, indent=4), encoding="utf-8")
        job_path.unlink(missing_ok=True)
        processed += 1

    try:
        write_services_snapshot(sd / "services_status.json")
    except Exception:
        pass

    print(f"OK processed={processed}")


if __name__ == "__main__":
    main()

