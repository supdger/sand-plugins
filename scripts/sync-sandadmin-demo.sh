#!/bin/zsh
set -euo pipefail

workspace_root="${0:A:h:h}"
source_root="${SANDADMIN_SOURCE:-${workspace_root:h}/sandadmin}"
target_root="${SANDADMIN_DEMO_ROOT:-${workspace_root}/sandadmin-demo}"
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
  print -u2 "SandAdmin demo is incomplete: $target_root"; exit 2;
}

source_state=clean
if [[ -n "$(git -C "$source_root" status --porcelain)" ]]; then
  source_state=dirty-local
  $allow_dirty || { print -u2 "SandAdmin source is dirty; use a clean published revision, or --allow-dirty for non-acceptance exploration."; exit 3; }
fi

rsync_arguments=(-a --delete-delay --itemize-changes)
$apply || rsync_arguments+=(--dry-run)
server_excludes=(
  '--exclude=.DS_Store' '--exclude=.env' '--exclude=.env.*' '--exclude=vendor/' '--exclude=runtime/'
  '--exclude=plugin/sandadmin/config/saithink.php'
  '--exclude=plugin/sandpackage/config/failed_upgrade_profiles.php'
  '--exclude=plugin/sand-*/***' '--exclude=plugin/sandworkflow/***'
  '--exclude=.artifacts/' '--exclude=.playwright-cli/'
)
frontend_excludes=(
  '--exclude=.DS_Store' '--exclude=.env' '--exclude=.env.*' '--exclude=node_modules/' '--exclude=dist/'
  '--exclude=.auto-import.json' '--exclude=src/types/import/'
  '--exclude=src/views/plugin/sand-*/***' '--exclude=src/views/plugin/sandworkflow/***'
  '--exclude=.artifacts/' '--exclude=.playwright-cli/'
)

print "Preparing SandAdmin demo from $source_root to $target_root ($source_state)"
rsync "${rsync_arguments[@]}" "${server_excludes[@]}" "$source_root/server/" "$target_root/server/"
rsync "${rsync_arguments[@]}" "${frontend_excludes[@]}" "$source_root/sandadmin-artd/" "$target_root/sandadmin-artd/"

if ! $apply; then
  print "Dry run only: no files were changed. Re-run with --apply after review and authorization."
  exit 0
fi

sandadmin_revision="$(git -C "$source_root" rev-parse HEAD)"
sandadmin_ref="$(git -C "$source_root" describe --tags --exact-match 2>/dev/null || print "commit:$sandadmin_revision")"
cat > "$workspace_root/sandadmin-demo.lock" <<EOF
format=1
consumer=sand_plugins
source_revision=$sandadmin_revision
source_ref=$sandadmin_ref
source_state=$source_state
synced_at_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)
target=sandadmin-demo
EOF
print "SandAdmin demo prepared at $sandadmin_revision ($source_state)."
