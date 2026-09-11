#!/bin/zsh
set -euo pipefail

script_name="${0:A:t}"

usage() {
  print -u2 "Usage: $script_name <sand-ai|sand-iam|sandworkflow> [--dry-run|--apply] [--allow-dirty]"
  exit 64
}

(( $# >= 1 )) || usage
plugin_id="$1"
shift

workspace_root="${0:A:h:h}"
demo_root="${SANDADMIN_DEMO_ROOT:-${workspace_root}/sandadmin-demo-host}"
apply=false
allow_dirty=false

case "$plugin_id" in
  sand-ai|sand-iam|sandworkflow) ;;
  *) print -u2 "Unsupported plugin: $plugin_id"; usage ;;
esac

for argument in "$@"; do
  case "$argument" in
    --dry-run) apply=false ;;
    --apply) apply=true ;;
    --allow-dirty) allow_dirty=true ;;
    *) print -u2 "Unknown argument: $argument"; usage ;;
  esac
done

source_root="${workspace_root}/${plugin_id}"
target_root="${demo_root}/plugins/${plugin_id}"
lock_root="${workspace_root}/demo-plugin-locks"

[[ -d "$workspace_root/.git" ]] || { print -u2 "sand_plugins is not a Git checkout: $workspace_root"; exit 2; }
[[ -d "$source_root" ]] || { print -u2 "Plugin source not found: $source_root"; exit 2; }
[[ -d "$demo_root/server" && -d "$demo_root/sandadmin-artd" ]] || {
  print -u2 "Demo host is incomplete: $demo_root"; exit 2;
}

source_state=clean
if [[ -n "$(git -C "$workspace_root" status --porcelain -- "$plugin_id")" ]]; then
  source_state=dirty-local
  $allow_dirty || {
    print -u2 "$plugin_id source is dirty; commit the plugin candidate, or use --allow-dirty for non-acceptance exploration."
    exit 3
  }
fi

rsync_arguments=(-a --delete-delay --itemize-changes)
$apply || rsync_arguments+=(--dry-run)
export_excludes=(
  '--exclude=.git/' '--exclude=.artifacts/' '--exclude=.backups/'
  '--exclude=.staging/' '--exclude=.tmp/' '--exclude=.pnpm-store/' '--exclude=.dart_tool/'
  '--exclude=node_modules/' '--exclude=vendor/' '--exclude=.playwright-cli/'
  '--exclude=.DS_Store'
)

print "Exporting $plugin_id from $source_root to $target_root ($source_state)"
rsync "${rsync_arguments[@]}" "${export_excludes[@]}" "$source_root/" "$target_root/"

if ! $apply; then
  print "Dry run only: no files were changed. Re-run with --apply after review and authorization."
  exit 0
fi

remaining="$(rsync -ani --delete-delay --itemize-changes "${export_excludes[@]}" "$source_root/" "$target_root/")"
[[ -z "$remaining" ]] || {
  print -u2 "Plugin export verification failed; remaining differences:"
  print -u2 "$remaining"
  exit 4
}

mkdir -p "$lock_root"
source_revision="$(git -C "$workspace_root" log -1 --format=%H -- "$plugin_id")"
[[ -n "$source_revision" ]] || { print -u2 "Cannot resolve source revision for $plugin_id"; exit 5; }
source_ref="$(git -C "$workspace_root" describe --tags --exact-match "$source_revision" 2>/dev/null || print "commit:$source_revision")"
cat > "$lock_root/${plugin_id}.lock" <<EOF
format=1
plugin=$plugin_id
source_revision=$source_revision
source_ref=$source_ref
source_state=$source_state
export_kind=source-tree
formal_lifecycle=false
synced_at_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)
target=sandadmin-demo-host/plugins/$plugin_id
EOF

print "$plugin_id export synchronized at $source_revision ($source_state)."
print "This is a source export only; install, upgrade, database, service and business acceptance remain separate gates."
