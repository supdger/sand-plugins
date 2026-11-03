#!/bin/zsh
set -euo pipefail

# A fresh checkout writes the delivered trees with the checkout's own mtimes, so
# rsync itemizes timestamp-only items against the SandAI source. --check and
# --dry-run must judge content alone and ignore those; real content drift must
# still fail.

repository_root="${0:A:h:h}"
fixture_root="$(mktemp -d "${TMPDIR:-/tmp}/deliver-sand-ai-mtime.XXXXXX")"
workspace_root="$fixture_root/workspace"
source_root="$fixture_root/source"
fake_bin="$fixture_root/bin"
deliver_script="$workspace_root/scripts/deliver-sand-ai.sh"

cleanup() {
  rm -rf -- "$fixture_root"
}
trap cleanup EXIT

fail() {
  print -u2 "deliver-sand-ai mtime regression test failed: $1"
  exit 1
}

mkdir -p "$workspace_root/scripts" "$workspace_root/sand-ai" "$fake_bin" \
  "$source_root/server/plugin/sand-ai/controller" "$source_root/server/app/Api" \
  "$source_root/server/app/controller" "$source_root/server/app/process" \
  "$source_root/server/config"
# `.git` only has to exist: the script checks the workspace is a checkout.
print -r -- 'gitdir: fixture' > "$workspace_root/.git"
cp "$repository_root/scripts/deliver-sand-ai.sh" \
  "$repository_root/scripts/check-staged-sand-ai-delivery.py" "$workspace_root/scripts/"
chmod 0700 "$deliver_script"

print -r -- '<?php // sand-ai plugin fixture' > "$source_root/server/plugin/sand-ai/plugin.php"
print -r -- '<?php // sand-ai plugin controller fixture' > "$source_root/server/plugin/sand-ai/controller/Demo.php"
print -r -- '<?php // sand-ai payload fixture' > "$source_root/server/app/Api/Demo.php"
print -r -- '<?php // host-owned, never delivered' > "$source_root/server/app/controller/Host.php"
print -r -- '<?php // webman-owned, never delivered' > "$source_root/server/app/process/Http.php"
print -r -- '<?php return [];' > "$source_root/server/config/sand_ai_demo.php"
print -r -- '<?php return []; // host-owned' > "$source_root/server/config/app.php"

cat > "$fake_bin/git" <<'EOF'
#!/bin/zsh
if [[ "$1" == "--version" ]]; then
  print 'git version fixture'
  exit 0
fi
if [[ "$*" == *"status --porcelain"* ]]; then
  exit 0
fi
if [[ "$*" == *"rev-parse HEAD"* ]]; then
  print '0123456789abcdef0123456789abcdef01234567'
  exit 0
fi
print -u2 "unexpected fixture git command: $*"
exit 70
EOF
chmod 0700 "$fake_bin/git"

run_delivery() {
  local mode="$1"
  set +e
  delivery_output="$(SAND_GIT_BIN="$fake_bin/git" "$deliver_script" "$mode" --source "$source_root" 2>&1)"
  delivery_status=$?
  set -e
}

run_delivery --apply
(( delivery_status == 0 )) || fail "--apply failed: $delivery_output"
[[ -f "$workspace_root/sand-ai/app/Api/Demo.php" && ! -e "$workspace_root/sand-ai/app/controller" ]] \
  || fail "--apply delivered the wrong payload: $(find "$workspace_root/sand-ai" -type f)"

run_delivery --check
(( delivery_status == 0 )) || fail "--check failed right after --apply: $delivery_output"

# Simulate a fresh checkout: identical bytes, unrelated timestamps.
find "$workspace_root/sand-ai" -exec touch -t 202001010101 {} +
itemized="$(rsync -rltn --checksum --itemize-changes \
  "$source_root/server/plugin/sand-ai/" "$workspace_root/sand-ai/plugin/sand-ai/")"
[[ "$itemized" == *'..t'* ]] || fail "fixture did not produce timestamp-only items: $itemized"

run_delivery --check
(( delivery_status == 0 )) || fail "--check rejected a content-identical tree over mtimes: $delivery_output"
run_delivery --dry-run
[[ "$delivery_output" != *'pending differences'* ]] \
  || fail "--dry-run reported mtime-only items as differences: $delivery_output"

# Same size and mtime, different bytes: still a real difference.
drifted="$workspace_root/sand-ai/plugin/sand-ai/controller/Demo.php"
/usr/bin/perl -0pi -e 's/fixture/drifted/' "$drifted"
touch -r "$source_root/server/plugin/sand-ai/controller/Demo.php" "$drifted"
run_delivery --check
(( delivery_status == 1 )) || fail "--check accepted drifted content: status=$delivery_status $delivery_output"
[[ "$delivery_output" == *'controller/Demo.php'* ]] \
  || fail "--check did not name the drifted file: $delivery_output"

print 'deliver-sand-ai mtime regression test passed: mtime-only items ignored, content drift still fails.'
