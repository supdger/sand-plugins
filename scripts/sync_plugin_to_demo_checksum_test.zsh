#!/bin/zsh
set -euo pipefail

repository_root="${0:A:h:h}"
sync_script="$repository_root/scripts/sync-plugin-to-demo.sh"
source_root="$repository_root/sand-iam"
fixture_root="$(mktemp -d "${TMPDIR:-/tmp}/sync-plugin-checksum.XXXXXX")"
demo_root="$fixture_root/demo-host"
target_root="$demo_root/plugins/sand-iam"
fake_bin="$fixture_root/bin"

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
mkdir -p "$demo_root/server" "$demo_root/sandadmin-artd" "$target_root" "$fake_bin"
cat > "$fake_bin/git" <<'EOF'
#!/bin/zsh
if [[ "$1" == "--version" ]]; then
  print 'git version fixture'
  exit 0
fi
if [[ "${FAKE_GIT_STATUS_FAIL:-0}" == "1" ]]; then
  print -u2 'fixture git status unavailable'
  exit 69
fi
if [[ "$*" == *"status --porcelain"* ]]; then
  print ' M sand-iam/fixture'
  exit 0
fi
print -u2 "unexpected fixture git command: $*"
exit 70
EOF
chmod 0700 "$fake_bin/git"
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

checksum_differences="$(SAND_GIT_BIN="$fake_bin/git" SANDADMIN_DEMO_ROOT="$demo_root" "$sync_script" sand-iam --dry-run --allow-dirty)"
for descriptor_path in "${descriptor_paths[@]}"; do
  [[ "$checksum_differences" == *"$descriptor_path"* ]] \
    || fail "checksum dry run missed $descriptor_path"
done

set +e
git_failure_output="$(SAND_GIT_BIN="$fake_bin/git" FAKE_GIT_STATUS_FAIL=1 SANDADMIN_DEMO_ROOT="$demo_root" "$sync_script" sand-iam --dry-run --allow-dirty 2>&1)"
git_failure_status=$?
set -e
[[ "$git_failure_status" == 6 && "$git_failure_output" == *'Cannot verify sand-iam source state; refusing export'* ]] \
  || fail "Git status failure did not fail closed: status=$git_failure_status output=$git_failure_output"

rsync -a --checksum --delete-delay "${export_excludes[@]}" "$source_root/" "$target_root/"
normal_differences="$(rsync -anic --delete-delay --itemize-changes "${export_excludes[@]}" "$source_root/" "$target_root/")"
[[ -z "$normal_differences" ]] || fail "checksum comparison found differences after fixture reset: $normal_differences"

print 'sync-plugin checksum regression test passed: drift detected, Git failure closed, identical tree reports 0 differences.'
