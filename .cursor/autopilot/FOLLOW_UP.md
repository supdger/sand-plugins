# Autopilot Goal continuation. Do not wait for the user to say 继续.

Truth source: `.cursor/autopilot/GOAL.md` + `.cursor/autopilot/tasks.md` + `sand-iam/docs/development/sand-iam-task-board.md`.
Drive by tasks, not by chat instructions.

1. Read GOAL.md (one glance), then take the first unchecked `- [ ]` in tasks.md only.
2. If DETECT-*:
   - Re-scan board, IAM-01 contract docs, `.codex/autopilot/tasks.md`, detect-status.
   - New frozen Cursor-consumable work → append `- [ ] U-xx` ABOVE DETECT; clear `detect_backoff_until`; leave DETECT unchecked.
   - Nothing claimable → update detect-status WAITING; write state `detect_backoff_until`=now+1h and `detect_mode`=waiting; keep `enabled`=true; NEVER `autopilot_ctl.py off` for DETECT idle; ensure `autopilot_detect_watch.py` is running.
3. Work tasks: implement → verify → `[x]` → `executions/<task-id>.md`.
4. Hard stop only: `BLOCKED:` under non-DETECT + `autopilot_ctl.py off`.
5. Cursor-owned paths only (`sand-iam/sandadmin-artd/src/views/plugin/sand-iam/` unless recorded handoff). Codex owns `sand-iam/plugin/sand-iam/`.
