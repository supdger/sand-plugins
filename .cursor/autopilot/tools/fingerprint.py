#!/usr/bin/env python3
"""Print DETECT watch fingerprint (workspace watch-paths.txt or SandAI defaults)."""
from __future__ import annotations

import hashlib
import sys
from pathlib import Path

DEFAULT_WATCH_RELATIVE = (
    Path("docs/development/sand-ai-task-board.md"),
    Path("docs/development/sand-ai-api-contract.md"),
    Path(".codex/autopilot/tasks.md"),
    Path(".codex/autopilot/state.json"),
)
WATCH_CONFIG_REL = Path(".cursor/autopilot/watch-paths.txt")


def watch_relative(root: Path) -> tuple[Path, ...]:
    cfg = root / WATCH_CONFIG_REL
    try:
        lines = cfg.read_text(encoding="utf-8").splitlines()
    except OSError:
        return DEFAULT_WATCH_RELATIVE
    paths = tuple(
        Path(line.strip())
        for line in lines
        if line.strip() and not line.lstrip().startswith("#")
    )
    return paths or DEFAULT_WATCH_RELATIVE


def fingerprint(root: Path) -> str:
    digest = hashlib.sha256()
    for rel in watch_relative(root):
        path = root / rel
        digest.update(str(rel).encode())
        digest.update(b"\0")
        try:
            digest.update(path.read_bytes())
        except OSError:
            digest.update(b"<missing>")
        digest.update(b"\0")
    return digest.hexdigest()[:16]


if __name__ == "__main__":
    root = Path(sys.argv[1] if len(sys.argv) > 1 else ".").resolve()
    print(fingerprint(root))
