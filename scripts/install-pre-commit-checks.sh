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
if [[ -f "$hook" ]] && /usr/bin/grep -q '^# sand-plugin-pre-commit-v1$' "$hook"; then
  echo "Repository pre-commit checks already installed."
  exit 0
fi
if [[ -e "$saved" ]]; then
  echo "Existing preserved hook needs explicit integration: $saved" >&2
  exit 1
fi
if [[ -e "$hook" ]]; then
  mv "$hook" "$saved"
fi
cat >"$hook" <<'HOOK'
#!/bin/bash
# sand-plugin-pre-commit-v1
set -euo pipefail
root="$(git rev-parse --show-toplevel)"
hook="$(git rev-parse --git-path hooks/pre-commit)"
case "$hook" in /*) ;; *) hook="$PWD/$hook";; esac
if [[ -f "$hook.before-sand-checks" ]]; then
  "$hook.before-sand-checks" "$@"
fi
/usr/bin/python3 "$root/scripts/check-staged-plugin-support.py"
/usr/bin/python3 /Users/supdger/.codex/tools/scan_change_quality.py "$root" --repository-review check
HOOK
chmod 755 "$hook"
echo "Repository pre-commit checks installed; preserved hook: $saved (if previously present)."
