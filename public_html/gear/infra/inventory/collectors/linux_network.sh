set -euo pipefail

if command -v ip >/dev/null 2>&1; then
  ip -o -4 addr show 2>/dev/null | awk '{print "ADDR4|" $2 "|" $4}'
  ip -o -6 addr show 2>/dev/null | awk '{print "ADDR6|" $2 "|" $4}'
  ip -4 route show 2>/dev/null | while IFS= read -r line; do printf 'ROUTE4|%s\n' "$line"; done
  ip -6 route show 2>/dev/null | while IFS= read -r line; do printf 'ROUTE6|%s\n' "$line"; done
fi

