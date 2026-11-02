#!/bin/bash
# Reject embedded git repositories in the worktree.
# A nested .git turns a directory into an invisible clone: git stops
# descending, tracked files appear deleted, and the whole tree shows as
# one phantom untracked entry. Registered submodules are exempt.
set -euo pipefail
root="$(git rev-parse --show-toplevel)"
cd "$root"
found=0
while IFS= read -r -d '' gitdir; do
  dir="$(dirname "$gitdir")"
  # registered submodule? check .gitmodules path entries
  if [[ -f .gitmodules ]] && git config --file .gitmodules --get-regexp '^submodule\..*\.path$' 2>/dev/null | awk '{print $2}' | grep -Fxq "$dir"; then
    continue
  fi
  echo "nested .git found: $dir (embedded clones are forbidden; land flat source or register a submodule)" >&2
  found=1
done < <(find . -name .git -not -path './.git' -prune -print0)
exit $found
