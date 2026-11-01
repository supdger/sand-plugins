#!/usr/bin/env python3
"""Verify a published plugin repository contains only user-facing payload.

Published plugin repositories (supdger/sand-core, supdger/sand-package,
supdger/sand-iam, supdger/sand-workflow, supdger/sand-ai) face end users,
not collaborators. Before (re)publishing an export from this monorepo, run
this check against the staged tree; anything that serves only development,
CI, or agent tooling is rejected.

Usage:
    python3 scripts/check-published-repo-content.py --plugin sand-iam --root /path/to/export
"""

import argparse
import re
import sys
from pathlib import Path, PurePosixPath

# Paths that never belong in a user-facing published repository, wherever
# they appear. Keep this list mechanical: each entry names content whose
# only audience is the development workflow, never the installing user.
DEV_PATH = re.compile(
    r"(?:^|/)"
    r"(?:\.github|\.vscode|\.husky|\.idea|\.codex|\.cursor|\.claude|\.agents|openspec|"
    r"node_modules|\.pnpm-store|\.dart_tool|\.staging|\.tmp|\.backups|backups|"
    r"artifacts|coverage|test-results|waiting-codex|__tests__|mocks?|fixtures?|tests?)"
    r"(?:/|$)"
    r"|(?:^|/)[^/]+\.(?:test|spec)\.[^/]+$"
    r"|(?:^|/)Test\.php$"
    r"|(?:^|/)[^/]*-mock\.[^/]+$"
    r"|(?:^|/)(?:commitlint\.config|eslint\.config)\.[^/]+$"
    r"|(?:^|/)\.(?:prettier|stylelint)[^/]*$"
    r"|(?:^|/)phpunit\.xml$"
    r"|(?:^|/)docs/(?:development|product|internal)(?:/|$)"
    r"|(?:^|/)AGENTS\.md$|(?:^|/)CLAUDE\.md$"
    r"|(?:^|/)\.env(?:\.(?!.*example)[^/]*)?$",
    re.IGNORECASE,
)

# Tooling test entries inside payload roots (e.g. tools/test-*.php,
# publish-frontend-test.php). `test` must sit on a separator boundary so
# names like `latest-release.php` are not flagged.
DEV_TOOL_TEST = re.compile(
    r"(?:^|/)(?:test[-_.][^/]*|[^/]*[-_.]test(?:[-_.][^/]*)?)\.(?:php|mjs|cjs|ts|dart)$",
    re.IGNORECASE,
)

# Per-plugin exemptions: directories whose matched contents are legitimate
# user-facing payload despite looking dev-shaped.
ALLOWED_PREFIXES = {
    # Runtime dependency tree is pinned by release-build-contract.json; its
    # internal upstream CI/phpunit files are a documented residual fixed at
    # the next re-vendor. Examples ship runnable verification tests.
    "sand-iam": ("plugin/sand-iam/vendor/", "examples/"),
    "sand-package": (),
    "sand-core": (),
    "sandworkflow": (),
    "sand-ai": (),
}

# The published GitHub repository for sandworkflow is named "sand-workflow".
PLUGIN_ALIASES = {"sand-workflow": "sandworkflow"}

# Consumer-facing compiled SDK output; every other dist/ tree is dev output.
ALLOWED_DIST = {
    "sand-iam": ("sdk/typescript/dist/",),
}

GENERIC_ALLOWED_ROOT = re.compile(
    r"^(?:README\.md|CHANGELOG\.md|CONTRIBUTING\.md|SECURITY\.md|LICENSE|NOTICE|"
    r"THIRD_PARTY_NOTICES\.md|SBOM\.cdx\.json|release-build-contract\.json|"
    r"config\.json|info\.ini|existing-schema\.json|composer\.json|composer\.lock|"
    r"install\.sql|update\.sql|uninstall\.sql|\.gitignore|\.gitattributes)$"
)


def violations(plugin: str, root: Path):
    allowed = ALLOWED_PREFIXES.get(plugin, ())
    allowed_dist = ALLOWED_DIST.get(plugin, ())
    hits = []
    for path in sorted(p for p in root.rglob("*") if p.is_file()):
        rel = PurePosixPath(path.relative_to(root)).as_posix()
        if rel == ".git" or rel.startswith(".git/"):
            continue
        if any(rel == a.rstrip("/") or rel.startswith(a) for a in allowed):
            continue
        if re.search(r"(?:^|/)dist(?:/|$)", rel) \
                and any(rel == a.rstrip("/") or rel.startswith(a) for a in allowed_dist):
            continue
        if DEV_PATH.search(rel):
            hits.append((rel, "development-only path"))
        elif re.search(r"(?:^|/)dist(?:/|$)", rel):
            hits.append((rel, "build output not declared consumer-facing"))
        elif DEV_TOOL_TEST.search(rel):
            hits.append((rel, "tooling test inside payload"))
    return hits


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--plugin", required=True,
                        choices=sorted(list(ALLOWED_PREFIXES) + list(PLUGIN_ALIASES)))
    parser.add_argument("--root", required=True, type=Path)
    args = parser.parse_args()
    plugin = PLUGIN_ALIASES.get(args.plugin, args.plugin)
    root = args.root.resolve()
    if not root.is_dir():
        print(f"[FAIL] {root} is not a directory", file=sys.stderr)
        return 2
    hits = violations(plugin, root)
    for rel, reason in hits:
        print(f"[FAIL] {plugin}: {rel} ({reason})")
    if hits:
        print(f"Published repo content check FAILED for {plugin}: {len(hits)} file(s).")
        return 1
    count = sum(1 for p in root.rglob("*") if p.is_file()
                and PurePosixPath(p.relative_to(root)).as_posix() != ".git"
                and not PurePosixPath(p.relative_to(root)).as_posix().startswith(".git/"))
    print(f"[PASS] published repo content: {plugin} ({count} files)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
