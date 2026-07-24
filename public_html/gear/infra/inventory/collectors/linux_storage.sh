set -euo pipefail

if command -v df >/dev/null 2>&1; then
  df -PT -B1 2>/dev/null | awk 'NR>1 {print "MOUNT|" $7 "|" $2 "|" $1 "|" $3 "|" $4}'
fi

