#!/usr/bin/env bash
set -euo pipefail

package_root="${SAND_WORKFLOW_SOURCE:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
plugin_root="${package_root}/plugin/sandworkflow"

fail() {
  echo "SandWorkflow package integrity failed: $1" >&2
  exit 1
}

for path in \
  "${package_root}/info.ini" \
  "${package_root}/config.json" \
  "${package_root}/README.md" \
  "${package_root}/LICENSE" \
  "${package_root}/NOTICE" \
  "${package_root}/install.sql" \
  "${package_root}/update.sql" \
  "${package_root}/uninstall.sql" \
  "${plugin_root}/info.ini" \
  "${plugin_root}/config/app.php"; do
  [[ -f "${path}" ]] || fail "missing ${path#${package_root}/}"
done

cmp -s "${package_root}/info.ini" "${plugin_root}/info.ini" \
  || fail "root and plugin info.ini files differ"

for name in install.sql update.sql uninstall.sql; do
  cmp -s "${package_root}/${name}" "${plugin_root}/${name}" \
    || fail "root and plugin ${name} files differ"
done

metadata_value() {
  local key="$1"
  sed -nE "s/^${key}[[:space:]]*=[[:space:]]*(.*)$/\\1/p" "${package_root}/info.ini" | head -n 1
}

app="$(metadata_value app)"
version="$(metadata_value version)"
support="$(metadata_value support)"
state="$(metadata_value state)"

[[ "${app}" == "sandworkflow" ]] || fail "app must be sandworkflow"
[[ "${version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail "version must be semantic"
[[ "${support}" == "6.x" ]] || fail "support must be 6.x"
[[ "${state}" == "2" ]] || fail "source package state must be 2"

grep -Fq "GNU AFFERO GENERAL PUBLIC LICENSE" "${package_root}/LICENSE" \
  || fail "LICENSE must contain the GNU Affero General Public License"
grep -Fq "Version 3, 19 November 2007" "${package_root}/LICENSE" \
  || fail "LICENSE must be AGPL version 3"
grep -Fq "https://github.com/zhangjinlibra/workflow-web" "${package_root}/NOTICE" \
  || fail "NOTICE must retain the workflow-web upstream source"
grep -Fq "GNU Affero General Public License version 3" "${package_root}/NOTICE" \
  || fail "NOTICE must retain the upstream AGPL-3.0 declaration"

grep -Eq "['\"]version['\"][[:space:]]*=>[[:space:]]*['\"]${version//./\\.}['\"]" \
  "${plugin_root}/config/app.php" \
  || fail "config/app.php version differs from info.ini"

echo "SandWorkflow package integrity passed: sandworkflow@${version}, SandAdmin ${support}."
