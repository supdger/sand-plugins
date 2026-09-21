#!/usr/bin/env bash
set -euo pipefail

source_root="${SAND_WORKFLOW_SOURCE:-/Users/code/project/sand_plugins/sandworkflow}"
host_root="${SANDADMIN_ROOT:-/Users/code/project/sand_plugins/sandadmin-demo-host}"
source_frontend="${source_root}/sandadmin-artd/src/views"

"${source_root}/tools/check-package-integrity.sh"

if [[ ! -d "${source_root}/plugin/sandworkflow" || ! -d "${source_frontend}/plugin/sandworkflow" ]]; then
  echo "SandWorkflow source payload is incomplete: ${source_root}" >&2
  exit 2
fi

component_count=0
while IFS= read -r quoted_component; do
  component="${quoted_component#\'}"
  component="${component%\'}"
  [[ "${component}" == *%* ]] && continue

  expected="${source_frontend}${component}.vue"
  if [[ ! -f "${expected}" ]]; then
    echo "Menu component is missing from the source payload: ${component}" >&2
    exit 1
  fi
  component_count=$((component_count + 1))
done < <(grep -oE "'/plugin/sandworkflow/[A-Za-z0-9/_-]+'" "${source_root}/install.sql" | sort -u)

if [[ "${component_count}" -eq 0 ]]; then
  echo "No SandWorkflow menu components found in install.sql" >&2
  exit 1
fi

declare -a comparisons=(
  "${source_root}|${host_root}/plugins/sandworkflow|package"
  "${source_root}/plugin/sandworkflow|${host_root}/server/plugin/sandworkflow|backend"
  "${source_frontend}/plugin/sandworkflow|${host_root}/sandadmin-artd/src/views/plugin/sandworkflow|frontend"
)

for comparison in "${comparisons[@]}"; do
  IFS='|' read -r source_path host_path label <<< "${comparison}"
  if [[ ! -d "${host_path}" ]]; then
    echo "SandWorkflow ${label} payload is missing from the host: ${host_path}" >&2
    exit 1
  fi
  diff -qr -x '.DS_Store' "${source_path}" "${host_path}"
done

echo "SandWorkflow source menu components: ${component_count}/${component_count} present."
echo "SandWorkflow package, backend, and frontend payloads match the SandAdmin host."
