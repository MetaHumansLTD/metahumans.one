set -euo pipefail

if command -v nvidia-smi >/dev/null 2>&1; then
  idx=0
  nvidia-smi --query-gpu=name,memory.total --format=csv,noheader,nounits 2>/dev/null | while IFS= read -r line; do
    name="$(printf '%s' "$line" | cut -d, -f1 | sed 's/^[ \t]*//;s/[ \t]*$//')"
    vram="$(printf '%s' "$line" | cut -d, -f2 | sed 's/^[ \t]*//;s/[ \t]*$//')"
    printf 'GPU|%s|%s|%s\n' "$idx" "$name" "$vram"
    idx=$((idx+1))
  done
fi

