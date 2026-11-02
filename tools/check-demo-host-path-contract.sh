#!/usr/bin/env bash
set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
demo_host_root="/Users/code/project/sand_demo"
pure_host_root="/Users/code/project/sandadmin"

require_text() {
  local file="$1"
  local text="$2"
  if ! grep -Fq -- "${text}" "${file}"; then
    echo "Demo-host path contract missing ${text} in ${file}" >&2
    exit 1
  fi
}

current_documents=(
  "${repository_root}/README.md"
  "${repository_root}/AGENTS.md"
  "${repository_root}/sand-ai/README.md"
  "${repository_root}/sandworkflow/README.md"
  "${repository_root}/sandworkflow/SOURCE_OF_TRUTH.md"
  "${repository_root}/sand-iam/docs/development/sand-iam-p0-contract.md"
)

for document in "${current_documents[@]}"; do
  require_text "${document}" "${demo_host_root}"
  require_text "${document}" "${pure_host_root}"
done

require_text "${repository_root}/sand-ai/tools/check-sandadmin-export.sh" \
  "SANDADMIN_ROOT:-${demo_host_root}"
require_text "${repository_root}/sandworkflow/tools/check-sandadmin-host.sh" \
  "SANDADMIN_ROOT:-${demo_host_root}"

echo "Cross-plugin demo-host path contract passed."
