#!/bin/bash
set -euo pipefail

# Install repository checks while preserving the existing local pre-commit hook.
root="$(git rev-parse --show-toplevel)"
if [[ -n "$(git config --get core.hooksPath || true)" ]]; then
  echo "Cannot install safely with custom core.hooksPath; preserve and integrate it explicitly." >&2
  exit 1
fi
hook="$(git rev-parse --git-path hooks/pre-commit)"
case "$hook" in /*) ;; *) hook="$PWD/$hook";; esac
saved="$hook.before-sand-checks"
if [[ -L "$hook" || -L "$saved" ]]; then
  echo "Cannot replace symbolic-link hooks safely." >&2
  exit 1
fi
if [[ -f "$hook" ]] && /usr/bin/grep -q '^# sand-plugin-pre-commit-v2$' "$hook"; then
  echo "Repository pre-commit checks already installed."
  exit 0
fi
# An earlier generated hook is replaced in place; only a foreign hook is preserved.
upgrade=false
if [[ -f "$hook" ]] && /usr/bin/grep -Eq '^# sand-plugin-pre-commit-v[0-9]+$' "$hook"; then
  upgrade=true
fi
if [[ "$upgrade" == false ]]; then
  if [[ -e "$saved" ]]; then
    echo "Existing preserved hook needs explicit integration: $saved" >&2
    exit 1
  fi
  if [[ -e "$hook" ]]; then
    mv "$hook" "$saved"
  fi
fi
cat >"$hook" <<'HOOK'
#!/bin/bash
# sand-plugin-pre-commit-v2
set -euo pipefail
root="$(git rev-parse --show-toplevel)"
hook="$(git rev-parse --git-path hooks/pre-commit)"
case "$hook" in /*) ;; *) hook="$PWD/$hook";; esac
if [[ -f "$hook.before-sand-checks" ]]; then
  "$hook.before-sand-checks" "$@"
fi
/usr/bin/python3 "$root/scripts/check-staged-plugin-support.py"
# Guarded: branches predating the SandAI delivery gate still commit normally.
if [[ -f "$root/scripts/check-staged-sand-ai-delivery.py" ]]; then
  /usr/bin/python3 "$root/scripts/check-staged-sand-ai-delivery.py"
fi
"$root/scripts/check-nested-git.sh"

/usr/bin/python3 /Users/supdger/.codex/tools/scan_change_quality.py "$root" --repository-review check
HOOK
chmod 755 "$hook"
echo "Repository pre-commit checks installed; preserved hook: $saved (if previously present)."

prepush="$(git rev-parse --git-path hooks/pre-push)"
case "$prepush" in /*) ;; *) prepush="$PWD/$prepush";; esac
if [[ ! -e "$prepush" ]]; then
cat >"$prepush" <<'PUSH'
#!/bin/bash
# sand-plugin-pre-push-v1
branch="$(git rev-parse --abbrev-ref HEAD)"
case "$branch" in
  main|master)
    echo "pre-push: refusing to push protected branch '$branch' without explicit ALLOW_PROTECTED_PUSH=1" >&2
    exit 1 ;;
  feat/*|fix/*|chore/*|docs/*|refactor/*|test/*|codex/*|hotfix/*) ;;
  *) echo "pre-push: branch '$branch' does not match conventional prefixes" >&2 ;;
esac
echo "pre-push: pushing branch '$branch'" >&2
exit 0
PUSH
chmod +x "$prepush"
fi
