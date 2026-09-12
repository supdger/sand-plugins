#!/bin/zsh
set -euo pipefail

repository_root="${0:A:h:h}"
sync_script="$repository_root/scripts/sync-plugin-to-demo.sh"
source_root="$repository_root/sand-iam"
fixture_root="$(mktemp -d "${TMPDIR:-/tmp}/sync-plugin-checksum.XXXXXX")"
demo_root="$fixture_root/demo-host"
target_root="$demo_root/plugins/sand-iam"

cleanup() {
  rm -rf -- "$fixture_root"
}
trap cleanup EXIT

fail() {
  print -u2 "sync-plugin checksum regression test failed: $1"
  exit 1
}

export_excludes=(
  '--exclude=.git/' '--exclude=.artifacts/' '--exclude=.backups/'
  '--exclude=.staging/' '--exclude=.tmp/' '--exclude=.pnpm-store/' '--exclude=.dart_tool/'
  '--exclude=node_modules/' '--exclude=vendor/' '--exclude=.playwright-cli/'
  '--exclude=.DS_Store'
)
descriptor_paths=(
  'recovery/failed-upgrade.v2.json'
  'plugin/sand-iam/recovery/failed-upgrade.v2.json'
)

[[ -x "$sync_script" && -d "$source_root" ]] || fail 'sync script or SandIAM source is unavailable'
mkdir -p "$demo_root/server" "$demo_root/sandadmin-artd" "$target_root"
rsync -a --checksum --delete-delay "${export_excludes[@]}" "$source_root/" "$target_root/"

for descriptor_path in "${descriptor_paths[@]}"; do
  source_file="$source_root/$descriptor_path"
  target_file="$target_root/$descriptor_path"
  [[ -f "$source_file" && -f "$target_file" ]] || fail "missing descriptor: $descriptor_path"

  /usr/bin/perl -0pi -e 's/sand-iam/sandXiam/' "$target_file"
  touch -r "$source_file" "$target_file"
  [[ "$(stat -f '%z' "$source_file")" == "$(stat -f '%z' "$target_file")" ]] \
    || fail "descriptor size changed: $descriptor_path"
  [[ "$(stat -f '%m' "$source_file")" == "$(stat -f '%m' "$target_file")" ]] \
    || fail "descriptor mtime changed: $descriptor_path"
  cmp -s "$source_file" "$target_file" && fail "descriptor content did not change: $descriptor_path"
done

legacy_differences="$(rsync -ani --delete-delay --itemize-changes "${export_excludes[@]}" "$source_root/" "$target_root/")"
for descriptor_path in "${descriptor_paths[@]}"; do
  [[ "$legacy_differences" != *"$descriptor_path"* ]] \
    || fail "legacy size+mtime comparison unexpectedly found $descriptor_path"
done

checksum_differences="$(SANDADMIN_DEMO_ROOT="$demo_root" "$sync_script" sand-iam --dry-run --allow-dirty)"
for descriptor_path in "${descriptor_paths[@]}"; do
  [[ "$checksum_differences" == *"$descriptor_path"* ]] \
    || fail "checksum dry run missed $descriptor_path"
done

rsync -a --checksum --delete-delay "${export_excludes[@]}" "$source_root/" "$target_root/"
normal_differences="$(rsync -anic --delete-delay --itemize-changes "${export_excludes[@]}" "$source_root/" "$target_root/")"
[[ -z "$normal_differences" ]] || fail "checksum comparison found differences after fixture reset: $normal_differences"

print 'sync-plugin checksum regression test passed: same-size/same-mtime drift detected; identical tree reports 0 differences.'
