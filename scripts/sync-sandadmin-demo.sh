#!/bin/zsh
set -euo pipefail

workspace_root="${0:A:h:h}"
source_root="${SANDADMIN_SOURCE:-${workspace_root:h}/sandadmin}"
target_root="${SANDADMIN_DEMO_ROOT:-/Users/code/project/sand_demo}"
apply=false
allow_dirty=false

for argument in "$@"; do
  case "$argument" in
    --dry-run) apply=false ;;
    --apply) apply=true ;;
    --allow-dirty) allow_dirty=true ;;
    *) print -u2 "Unknown argument: $argument"; exit 64 ;;
  esac
done

[[ -d "$source_root/.git" ]] || { print -u2 "SandAdmin source is not a Git checkout: $source_root"; exit 2; }
[[ -f "$source_root/server/composer.json" && -f "$source_root/sandadmin-artd/package.json" ]] || {
  print -u2 "SandAdmin source is incomplete: $source_root"; exit 2;
}
[[ -d "$target_root/server" && -d "$target_root/sandadmin-artd" ]] || {
  print -u2 "Demo host is incomplete: $target_root"; exit 2;
}

source_state=clean
if [[ -n "$(git -C "$source_root" status --porcelain)" ]]; then
  source_state=dirty-local
  $allow_dirty || { print -u2 "SandAdmin source is dirty; use a clean published revision, or --allow-dirty for non-acceptance exploration."; exit 3; }
fi

rsync_arguments=(-a --checksum --delete-after --itemize-changes)
$apply || rsync_arguments+=(--dry-run)
server_excludes=(
  '--exclude=.DS_Store' '--include=.env.example' '--include=.env.*.example'
  '--exclude=.env' '--exclude=.env.*' '--exclude=vendor/' '--exclude=runtime/'
  '--exclude=/storage/sandpackage/***'
  '--exclude=/config/database.php' '--exclude=/config/think-orm.php' '--exclude=/config/think-cache.php'
  '--exclude=/config/cache.php' '--exclude=/config/redis.php'
  '--exclude=/config/plugin/tinywan/jwt/***'
  '--exclude=plugin/sandadmin/config/saithink.php'
  '--exclude=plugin/sandpackage/config/failed_upgrade_profiles.php'
  '--exclude=plugin/sand-*/***' '--exclude=plugin/sandworkflow/***'
  '--exclude=.artifacts/' '--exclude=.playwright-cli/' '--exclude=.tmp/'
)
frontend_excludes=(
  '--exclude=.DS_Store' '--include=.env.example' '--include=.env.*.example'
  '--exclude=.env' '--exclude=.env.*' '--exclude=node_modules/' '--exclude=dist/'
  '--exclude=.auto-import.json' '--exclude=src/types/import/'
  '--exclude=src/views/plugin/sand-*/***' '--exclude=src/views/plugin/sandworkflow/***'
  '--exclude=.artifacts/' '--exclude=.playwright-cli/' '--exclude=.tmp/'
)

jwt_config="$target_root/server/config/plugin/tinywan/jwt/app.php"
jwt_config_state=available
if [[ ! -f "$jwt_config" ]]; then
  jwt_config_state=missing
elif [[ ! -r "$jwt_config" ]]; then
  jwt_config_state=unreadable
elif command grep -Eq '__JWT_(ACCESS|REFRESH)_SECRET_KEY__' "$jwt_config"; then
  jwt_config_state=placeholder
else
  grep_status=$?
  (( grep_status > 1 )) && jwt_config_state=unreadable
fi
print "Demo host JWT runtime config: $jwt_config_state"
if [[ "$jwt_config_state" != available ]]; then
  print -u2 "Demo host JWT secrets are $jwt_config_state; refusing to continue. Initialize tinywan/jwt for this host (each secret at least 32 random bytes, never the __JWT_*__ placeholder) under explicit key/service authorization."
  exit 4
fi

print "Synchronizing SandAdmin host from $source_root to $target_root ($source_state)"
rsync "${rsync_arguments[@]}" "${server_excludes[@]}" "$source_root/server/" "$target_root/server/"
rsync "${rsync_arguments[@]}" "${frontend_excludes[@]}" "$source_root/sandadmin-artd/" "$target_root/sandadmin-artd/"

if ! $apply; then
  print "Dry run only: no files were changed. Re-run with --apply after review and authorization."
  exit 0
fi

host_revision="$(git -C "$source_root" rev-parse HEAD)"
host_ref="$(git -C "$source_root" describe --tags --exact-match 2>/dev/null || print "commit:$host_revision")"
cat > "$target_root/sandadmin-host.lock" <<EOF
format=1
consumer=sand_demo
source_revision=$host_revision
source_ref=$host_ref
source_state=$source_state
synced_at_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)
target=/Users/code/project/sand_demo
EOF
print "SandAdmin host synchronized at $host_revision ($source_state)."
