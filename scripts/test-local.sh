#!/bin/bash
set -euo pipefail

# Run one selected local regression without invoking SDK wrappers or CLI IPC servers.
project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
case "${1:-}" in
  iam-helpers)
    cd "$project_root"
    exec "${NODE_BIN:-node}" \
      --import ./sandadmin-demo/sandadmin-artd/node_modules/tsx/dist/loader.mjs \
      sand-iam/sandadmin-artd/tests/human-ux-helper-contract.test.ts
    ;;
  iam-dart)
    dart_bin="${DART_BIN:-$HOME/fvm/versions/stable/bin/cache/dart-sdk/bin/dart}"
    if [[ ! -x "$dart_bin" ]]; then
      echo "Set DART_BIN to the installed Dart SDK executable." >&2
      exit 1
    fi
    cd "$project_root/sand-iam/sdk/dart"
    exec "$dart_bin" --suppress-analytics test test/management_client_test.dart
    ;;
  *)
    echo "Usage: bash scripts/test-local.sh {iam-helpers|iam-dart}" >&2
    exit 2
    ;;
esac
