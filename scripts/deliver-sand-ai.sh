#!/bin/zsh
set -euo pipefail

# Mirror the SandAI plugin payload from its development source of truth into
# this delivery monorepo. The delivery tree is generated, never hand-edited;
# scripts/check-staged-sand-ai-delivery.py enforces that at commit time.

script_name="${0:A:t}"

usage() {
  print -u2 "Usage: $script_name [--dry-run|--apply|--check] [--source <sand_ai checkout>]"
  exit 64
}

workspace_root="${0:A:h:h}"
source_root="${SAND_AI_SOURCE_ROOT:-/Users/code/project/sand_ai}"
source_subdir='server/plugin/sand-ai'
target_subdir='sand-ai/plugin/sand-ai'
record_subpath='sand-ai/.delivery-source.json'
mode=dry-run

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
[[ -d "$source_root/$source_subdir" ]] || {
  print -u2 "Source payload not found: $source_root/$source_subdir"; exit 2;
}

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
if ! source_status="$("$git_bin" -C "$source_root" status --porcelain -- "$source_subdir" 2>&1)"; then
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
rsync_arguments=(-rlt --checksum --delete-delay --itemize-changes)
# Runtime, dependency and credential shaped files never cross into delivery.
rsync_excludes=(
  '--include=.env.example'
  '--exclude=.git/' '--exclude=.git' '--exclude=vendor/' '--exclude=node_modules/'
  '--exclude=runtime/' '--exclude=storage/' '--exclude=tmp/' '--exclude=.tmp/'
  '--exclude=.idea/' '--exclude=.vscode/' '--exclude=.DS_Store'
  '--exclude=.env' '--exclude=.env.*' '--exclude=*.key' '--exclude=*.pem'
  '--exclude=credentials.json' '--exclude=secrets/' '--exclude=*.log'
)
source_tree="$source_root/$source_subdir/"
target_tree="$workspace_root/$target_subdir/"

digest_tree() {
  /usr/bin/python3 "$workspace_root/scripts/check-staged-sand-ai-delivery.py" \
    --digest-tree "$workspace_root/$target_subdir"
}

print "SandAI delivery ($mode): $source_root/$source_subdir -> $target_subdir at $source_commit"

if [[ "$mode" == apply ]]; then
  rsync "${rsync_arguments[@]}" "${rsync_excludes[@]}" "$source_tree" "$target_tree"
  chmod -R go-w "${target_tree%/}"
fi

remaining="$(rsync -n "${rsync_arguments[@]}" "${rsync_excludes[@]}" "$source_tree" "$target_tree")"

if [[ "$mode" == dry-run ]]; then
  if [[ -n "$remaining" ]]; then
    print "Pending delivery differences:"
    print "$remaining"
  else
    print "Delivery tree already matches the source payload."
  fi
  print "Dry run only: no files were changed. Re-run with --apply to deliver."
  exit 0
fi

if [[ "$mode" == apply ]]; then
  [[ -z "$remaining" ]] || {
    print -u2 "Delivery verification failed; remaining differences:"
    print -u2 "$remaining"
    exit 4
  }
  digest_line="$(digest_tree)"
  cat > "$workspace_root/$record_subpath" <<EOF
{
  "format": 1,
  "source_repo": "${source_root:t}",
  "source_commit": "$source_commit",
  "source_subdir": "$source_subdir",
  "target_subdir": "$target_subdir",
  "generated_at_utc": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
  "file_count": ${digest_line##* },
  "content_sha256": "${digest_line%% *}"
}
EOF
  print "Delivered ${digest_line##* } files at ${digest_line%% *}; recorded in $record_subpath."
  print "This is a source delivery only; packaging, install, database and business acceptance remain separate gates."
  exit 0
fi

# --check: verify without writing, for review and automation.
failed=false
if [[ -n "$remaining" ]]; then
  print -u2 "Delivery tree differs from the source payload:"
  print -u2 "$remaining"
  failed=true
fi
if [[ ! -f "$workspace_root/$record_subpath" ]]; then
  print -u2 "Missing delivery record: $record_subpath"
  failed=true
else
  digest_line="$(digest_tree)"
  record_commit="$(/usr/bin/python3 -c 'import json,sys;print(json.load(open(sys.argv[1]))["content_sha256"],json.load(open(sys.argv[1]))["source_commit"])' "$workspace_root/$record_subpath")"
  if [[ "${digest_line%% *}" != "${record_commit%% *}" ]]; then
    print -u2 "Delivery record digest does not match the delivery tree: $record_subpath"
    failed=true
  fi
  if [[ "${record_commit##* }" != "$source_commit" ]]; then
    print -u2 "Delivery record pins ${record_commit##* } but the source is at $source_commit"
    failed=true
  fi
fi
$failed && { print -u2 "Run $script_name --apply to regenerate the delivery."; exit 1; }
print "Delivery check passed: tree and record match $source_commit."
exit 0
