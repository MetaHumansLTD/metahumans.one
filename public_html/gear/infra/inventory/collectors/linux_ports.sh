set -euo pipefail

if command -v ss >/dev/null 2>&1; then
  ss -H -lntu 2>/dev/null | awk '{print "PORT|" $1 "|" $5}'
fi

