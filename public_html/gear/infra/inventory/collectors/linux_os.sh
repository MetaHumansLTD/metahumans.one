set -euo pipefail

os_pretty=""
if [ -r /etc/os-release ]; then
  os_pretty="$(. /etc/os-release; printf '%s' "${PRETTY_NAME:-}")"
fi
kernel="$(uname -r 2>/dev/null || true)"
hostname_fqdn="$(hostname -f 2>/dev/null || hostname 2>/dev/null || true)"
uptime_sec="$(cut -d. -f1 /proc/uptime 2>/dev/null || true)"

printf 'OS_PRETTY=%s\n' "$os_pretty"
printf 'KERNEL=%s\n' "$kernel"
printf 'HOSTNAME=%s\n' "$hostname_fqdn"
printf 'UPTIME_SEC=%s\n' "$uptime_sec"

