set -euo pipefail

vendor=""
model=""
sockets=""
cores_per_socket=""
threads_total=""

if command -v lscpu >/dev/null 2>&1; then
  vendor="$(lscpu 2>/dev/null | awk -F: '/Vendor ID/ {gsub(/^[ \t]+/, "", $2); print $2; exit}')"
  model="$(lscpu 2>/dev/null | awk -F: '/Model name/ {gsub(/^[ \t]+/, "", $2); print $2; exit}')"
  sockets="$(lscpu 2>/dev/null | awk -F: '/Socket\(s\)/ {gsub(/^[ \t]+/, "", $2); print $2; exit}')"
  cores_per_socket="$(lscpu 2>/dev/null | awk -F: '/Core\(s\) per socket/ {gsub(/^[ \t]+/, "", $2); print $2; exit}')"
  threads_total="$(lscpu 2>/dev/null | awk -F: '/^CPU\(s\):/ {gsub(/^[ \t]+/, "", $2); print $2; exit}')"
fi

ram_kb="$(awk '/MemTotal/ {print $2; exit}' /proc/meminfo 2>/dev/null || true)"

printf 'CPU_VENDOR=%s\n' "$vendor"
printf 'CPU_MODEL=%s\n' "$model"
printf 'CPU_SOCKETS=%s\n' "$sockets"
printf 'CPU_CORES_PER_SOCKET=%s\n' "$cores_per_socket"
printf 'CPU_THREADS_TOTAL=%s\n' "$threads_total"
printf 'RAM_KB=%s\n' "$ram_kb"
