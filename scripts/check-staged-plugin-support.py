#!/usr/bin/env python3
"""Check changed plugin metadata from the actual Git index, never dirty peers."""

import configparser
import re
import subprocess
import sys
from pathlib import PurePosixPath

PLUGIN_ROOTS = {"sand-iam", "sand-ai", "sandworkflow"}
OPEN_MINIMUM = re.compile(r">=(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)")


def git(*arguments):
    return subprocess.run(["git", *arguments], check=True, stdout=subprocess.PIPE).stdout


def support(path):
    source = git("show", ":" + path).decode("utf-8")
    parser = configparser.ConfigParser(interpolation=None, strict=True)
    parser.read_string("[metadata]\n" + source)
    # Existing info.ini files contain [app]; preserve either established layout.
    section = "app" if parser.has_section("app") else "metadata"
    value = parser.get(section, "support", fallback=None)
    if value is not None and len(value) >= 2 and value[0] == value[-1] and value[0] in "\"'":
        value = value[1:-1]
    if value is None or OPEN_MINIMUM.fullmatch(value) is None:
        raise ValueError(
            f"{path}: host compatibility rule conflict: use >=host_min with an open upper bound; "
            "wildcards/closed ranges require the documented historical catalog boundary evidence"
        )
    return value


def main():
    changed = git("diff", "--cached", "--no-renames", "--name-only", "--diff-filter=ACMRD", "-z").decode("utf-8").split("\0")
    indexed = set(git("ls-files", "-z").decode("utf-8").split("\0"))
    roots = set()
    for name in changed:
        parts = PurePosixPath(name).parts
        if len(parts) == 2 and parts[0] in PLUGIN_ROOTS and parts[-1] == "info.ini":
            roots.add(parts[0])
        elif len(parts) == 4 and parts[0] in PLUGIN_ROOTS and parts[1] == "plugin" and parts[-1] == "info.ini":
            roots.add(parts[0])
    for root in sorted(roots):
        outer = root + "/info.ini"
        peers = sorted(name for name in indexed if
                       len(PurePosixPath(name).parts) == 4
                       and name.startswith(root + "/plugin/")
                       and name.endswith("/info.ini"))
        if outer not in indexed or len(peers) != 1:
            raise ValueError(f"{root}: missing or ambiguous staged root/plugin info.ini pair")
        if support(outer) != support(peers[0]):
            raise ValueError(f"{root}: staged root/plugin support declarations differ")
        print(f"[PASS] staged host support: {root}")
    print(f"Staged host support check passed ({len(roots)} changed plugin pairs).")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (ValueError, UnicodeError, configparser.Error, subprocess.CalledProcessError) as error:
        print(f"[FAIL] {error}", file=sys.stderr)
        raise SystemExit(1)
