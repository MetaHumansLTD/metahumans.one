#!/bin/sh
set -eu

project_secret_file() {
  var_name="$1"
  value="$(printenv "$var_name" 2>/dev/null || true)"

  if [ -z "$value" ]; then
    return 0
  fi

  case "$value" in
    /var/run/secrets/*)
      if [ ! -f "$value" ]; then
        return 0
      fi

      source_dir_name="$(basename "$(dirname "$value")")"
      target_dir="/data/runtime-secrets/$source_dir_name"
      target_path="$target_dir/$(basename "$value")"

      mkdir -p "$target_dir"
      cp "$value" "$target_path"
      chown www-data:www-data "$target_path"
      chmod 0640 "$target_path"

      export "$var_name=$target_path"
      ;;
  esac
}

project_secret_file "COZA_CERT_PATH"
project_secret_file "COZA_CA_FILE"

exec "$@"
