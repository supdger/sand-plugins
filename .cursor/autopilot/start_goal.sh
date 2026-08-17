#!/usr/bin/env bash
# Arm / resume Cursor Autopilot Goal for sand_plugins / SandIAM.
# Wake phrase from user: 「恢复启动 Autopilot」→ Agent runs this script, then executes first open task.
set -euo pipefail
ROOT="${1:-/Users/code/project/sand_plugins}"
rm -f "${ROOT}/.cursor/autopilot/HARD_STOP"
python3 ~/.cursor/hooks/autopilot_ctl.py on "$ROOT" >/dev/null
python3 - <<PY
import json
from datetime import datetime, timezone
from pathlib import Path
root = Path("$ROOT")
state_path = root / ".cursor/autopilot/state.json"
state = json.loads(state_path.read_text()) if state_path.is_file() else {}
now = datetime.now(timezone.utc).replace(microsecond=0)
state.update({
    "enabled": True,
    "mode": "run_all",
    "updated_at": now.isoformat(),
    "detect_mode": "active",
    "detect_backoff_seconds": int(state.get("detect_backoff_seconds") or 3600),
    "same_task_continuations": 0,
})
state.pop("disabled_reason", None)
state.pop("detect_backoff_until", None)
state_path.write_text(json.dumps(state, ensure_ascii=False, indent=2) + "\n")
status = root / ".cursor/autopilot/detect-status.md"
status.write_text(
    "# Detect status\n\n"
    f"- updated_at: {now.isoformat()}\n"
    "- mode: **RESUMED** (user: 恢复启动 Autopilot)\n"
    "- Goal: enabled=true; HARD_STOP cleared; loop_limit restored by ctl on\n"
    "- next: first unchecked task in tasks.md\n",
    encoding="utf-8",
)
print(json.dumps({"enabled": True, "goal": str(root / ".cursor/autopilot/GOAL.md")}, ensure_ascii=False))
PY
if ! pgrep -f "autopilot_detect_watch.py ${ROOT}" >/dev/null 2>&1; then
  nohup python3 ~/.cursor/hooks/autopilot_detect_watch.py "$ROOT" >>"${ROOT}/.cursor/autopilot/watch.log" 2>&1 &
  echo "watcher pid $!"
else
  echo "watcher already running"
fi
echo "Autopilot resumed. Execute first unchecked task in .cursor/autopilot/tasks.md"
