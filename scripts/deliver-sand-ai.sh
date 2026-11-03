#!/bin/zsh
set -euo pipefail

# Mirror the SandAI payload from its development source of truth into this
# delivery monorepo. The delivery trees are generated, never hand-edited;
# scripts/check-staged-sand-ai-delivery.py enforces that at commit time.

script_name="${0:A:t}"

usage() {
  print -u2 "Usage: $script_name [--dry-run|--apply|--check] [--source <sand_ai checkout>]"
  exit 64
}

workspace_root="${0:A:h:h}"
source_root="${SAND_AI_SOURCE_ROOT:-/Users/code/project/sand_ai}"
record_subpath='sand-ai/.delivery-source.json'
mode=dry-run

# Delivered trees: SandAI source subdir -> target subdir in this repo. Anything
# else under sand-ai/ (README, CHANGELOG, lifecycle SQL, tools, tests, package
# metadata) is owned by this repo and never synced.
typeset -a unit_sources unit_targets
unit_sources=('server/plugin/sand-ai' 'server/app' 'server/config')
unit_targets=('sand-ai/plugin/sand-ai' 'sand-ai/app' 'sand-ai/config')

# server/app and server/config also hold host-owned files, so those units carry
# filters that select the SandAI payload inside them.
set_unit_filters() {
  filters=()
  case "$1" in
    server/app)
      # Http.php and Monitor.php are Webman's own processes; app/controller,
      # app/middleware, app/model, app/view and app/functions.php belong to the
      # host application, not to the SandAI payload.
      filters=('--exclude=/process/Http.php' '--exclude=/process/Monitor.php')
      local kept
      for kept in Api Application Identity Infrastructure Mcp command process; do
        filters+=("--include=/$kept/" "--include=/$kept/**")
      done
      filters+=('--exclude=*')
      ;;
    server/config)
      filters=('--include=/sand_ai_*.php' '--exclude=*')
      ;;
  esac
}

while (( $# )); do
  case "$1" in
    --dry-run) mode=dry-run ;;
    --apply) mode=apply ;;
    --check) mode=check ;;
    --source) shift; (( $# )) || usage; source_root="$1" ;;
    --source=*) source_root="${1#--source=}" ;;
    *) print -u2 "Unknown argument: $1"; usage ;;
  esac
  shift
done

[[ -e "$workspace_root/.git" ]] || { print -u2 "sand_plugins is not a Git checkout: $workspace_root"; exit 2; }
[[ -d "$source_root" ]] || { print -u2 "Source checkout not found: $source_root"; exit 2; }
source_root="${source_root:A}"
for source_subdir in "${unit_sources[@]}"; do
  [[ -d "$source_root/$source_subdir" ]] || {
    print -u2 "Source payload not found: $source_root/$source_subdir"; exit 2;
  }
done

git_bin="${SAND_GIT_BIN:-}"
if [[ -n "$git_bin" ]]; then
  [[ "$git_bin" == /* && -x "$git_bin" ]] || {
    print -u2 "SAND_GIT_BIN must be an absolute executable path: $git_bin"
    exit 6
  }
  "$git_bin" --version >/dev/null 2>&1 || {
    print -u2 "Configured Git executable is unavailable: $git_bin"
    exit 6
  }
else
  git_bin=''
  for git_candidate in ${(f)"$(whence -a -p git 2>/dev/null)"}; do
    [[ "$git_candidate" == /* && -x "$git_candidate" ]] || continue
    if "$git_candidate" --version >/dev/null 2>&1; then
      git_bin="$git_candidate"
      break
    fi
  done
  [[ -n "$git_bin" ]] || {
    print -u2 "No working Git executable is available; refusing delivery."
    exit 6
  }
fi

# A dirty source payload cannot be recorded as a reproducible commit, so the
# delivery is refused instead of pinning a revision the source never had.
if ! source_status="$("$git_bin" -C "$source_root" status --porcelain -- "${unit_sources[@]}" 2>&1)"; then
  print -u2 "Cannot verify SandAI source state; refusing delivery:"
  print -u2 "$source_status"
  exit 6
fi
[[ -z "$source_status" ]] || {
  print -u2 "SandAI source payload is dirty; commit it in $source_root first:"
  print -u2 "$source_status"
  exit 3
}
if ! source_commit="$("$git_bin" -C "$source_root" rev-parse HEAD 2>&1)"; then
  print -u2 "Cannot resolve SandAI source commit:"
  print -u2 "$source_commit"
  exit 5
fi

# Compare by content so matching size and mtime cannot hide a divergent payload.
# Permissions are deliberately not mirrored (`-rlt`, no `-p`): the development
# checkout is group/other writable and local openrsync ignores `--chmod`, so the
# delivery normalises modes itself after transfer.
rsync_arguments=(-rlt --checksum --delete-delay --delete-excluded --itemize-changes)
# Runtime, dependency and credential shaped files never cross into delivery.
rsync_excludes=(
  '--include=.env.example'
  '--exclude=.git/' '--exclude=.git' '--exclude=vendor/' '--exclude=node_modules/'
  '--exclude=runtime/' '--exclude=storage/' '--exclude=tmp/' '--exclude=.tmp/'
  '--exclude=.idea/' '--exclude=.vscode/' '--exclude=.DS_Store'
  '--exclude=.env' '--exclude=.env.*' '--exclude=*.key' '--exclude=*.pem'
  '--exclude=credentials.json' '--exclude=secrets/' '--exclude=*.log'
)

# `-t` keeps mtimes, so a tree whose content already matches still itemizes
# timestamp-only items (`.f..t....`, `.d..t....`) whenever the local files were
# written at a different moment, as in any fresh checkout. Content comparison
# drops those; every transfer, creation, deletion or content change keeps a
# non-timestamp marker and survives this filter.
content_differences() {
  local -a kept
  local line flags
  for line in ${(f)1}; do
    [[ -n "$line" ]] || continue
    flags="${line%% *}"
    # `YX` is the update/type prefix; the rest are the attribute markers.
    if [[ "$flags" == .[fdLDS]* && "${${flags:2}//[.+]/}" == t ]]; then
      continue
    fi
    kept+=("$line")
  done
  print -r -- "${(F)kept}"
}

digest_tree() {
  /usr/bin/python3 "$workspace_root/scripts/check-staged-sand-ai-delivery.py" \
    --digest-tree "$workspace_root/$1"
}

# `$filters` is set per unit; `--delete-excluded` makes the filters authoritative
# on both sides, so a file the delivery no longer selects cannot linger here.
unit_rsync() {
  local source_subdir="$1" target_subdir="$2"
  shift 2
  set_unit_filters "$source_subdir"
  rsync "$@" "${rsync_arguments[@]}" "${rsync_excludes[@]}" "${filters[@]}" \
    "$source_root/$source_subdir/" "$workspace_root/$target_subdir/"
}

typeset -a filters unit_records
typeset -i unit_index total_files=0
failed=false

print "SandAI delivery ($mode): $source_root -> sand-ai/ at $source_commit"

for (( unit_index = 1; unit_index <= $#unit_sources; unit_index++ )); do
  source_subdir="$unit_sources[$unit_index]"
  target_subdir="$unit_targets[$unit_index]"
  print "  unit: $source_subdir -> $target_subdir"

  if [[ "$mode" == apply ]]; then
    unit_rsync "$source_subdir" "$target_subdir"
    chmod -R go-w "$workspace_root/$target_subdir"
  fi

  remaining="$(unit_rsync "$source_subdir" "$target_subdir" -n)"
  # --apply has just written the tree, so it verifies the full itemize output;
  # the read-only modes only judge content.
  [[ "$mode" == apply ]] || remaining="$(content_differences "$remaining")"

  case "$mode" in
    dry-run)
      if [[ -n "$remaining" ]]; then
        print "  pending differences:"
        print "$remaining"
      else
        print "  already matches the source payload."
      fi
      ;;
    apply)
      [[ -z "$remaining" ]] || {
        print -u2 "Delivery verification failed for $target_subdir; remaining differences:"
        print -u2 "$remaining"
        exit 4
      }
      digest_line="$(digest_tree "$target_subdir")"
      total_files+=${digest_line##* }
      unit_records+=("    {
      \"source_subdir\": \"$source_subdir\",
      \"target_subdir\": \"$target_subdir\",
      \"file_count\": ${digest_line##* },
      \"content_sha256\": \"${digest_line%% *}\"
    }")
      ;;
    check)
      if [[ -n "$remaining" ]]; then
        print -u2 "$target_subdir differs from the source payload:"
        print -u2 "$remaining"
        failed=true
      fi
      ;;
  esac
done

if [[ "$mode" == dry-run ]]; then
  print "Dry run only: no files were changed. Re-run with --apply to deliver."
  exit 0
fi

if [[ "$mode" == apply ]]; then
  cat > "$workspace_root/$record_subpath" <<EOF
{
  "format": 2,
  "source_repo": "${source_root:t}",
  "source_commit": "$source_commit",
  "generated_at_utc": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
  "units": [
${(pj:,\n:)unit_records}
  ]
}
EOF
  print "Delivered $total_files files across $#unit_targets trees; recorded in $record_subpath."
  print "This is a source delivery only; packaging, install, database and business acceptance remain separate gates."
  exit 0
fi

# --check: verify without writing, for review and automation.
if [[ ! -f "$workspace_root/$record_subpath" ]]; then
  print -u2 "Missing delivery record: $record_subpath"
  failed=true
else
  record_commit="$(/usr/bin/python3 - "$workspace_root/$record_subpath" <<'PY'
import json, sys
record = json.load(open(sys.argv[1]))
print(record.get("source_commit", ""))
for unit in record.get("units", []):
    print(unit["target_subdir"], unit["content_sha256"], unit["file_count"])
PY
)"
  recorded_units="$(print -r -- "$record_commit" | tail -n +2)"
  record_commit="$(print -r -- "$record_commit" | head -n 1)"
  if [[ "$record_commit" != "$source_commit" ]]; then
    print -u2 "Delivery record pins $record_commit but the source is at $source_commit"
    failed=true
  fi
  if [[ "$(print -r -- "$recorded_units" | wc -l | tr -d ' ')" != "$#unit_targets" ]]; then
    print -u2 "Delivery record does not cover every delivered tree: $record_subpath"
    failed=true
  fi
  while read -r recorded_target recorded_sha256 recorded_count; do
    [[ -n "$recorded_target" ]] || continue
    if (( ${unit_targets[(Ie)$recorded_target]} == 0 )); then
      print -u2 "Delivery record holds an unknown tree $recorded_target: $record_subpath"
      failed=true
      continue
    fi
    digest_line="$(digest_tree "$recorded_target")"
    if [[ "${digest_line%% *}" != "$recorded_sha256" || "${digest_line##* }" != "$recorded_count" ]]; then
      print -u2 "Delivery record digest does not match $recorded_target: $record_subpath"
      failed=true
    fi
  done <<< "$recorded_units"
fi
$failed && { print -u2 "Run $script_name --apply to regenerate the delivery."; exit 1; }
print "Delivery check passed: trees and record match $source_commit."
exit 0
