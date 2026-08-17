# Cursor Autopilot Goal（对齐 Codex「进行中的目标」）

> **任务驱动，不是指令驱动。** 本文件是 Cursor 侧持久目标；不要等用户说「继续」。

## 目标

按 [`sand-iam/docs/development/sand-iam-task-board.md`](../../sand-iam/docs/development/sand-iam-task-board.md) 自动推进 **Cursor 前端**：

1. 执行 `.cursor/autopilot/tasks.md` 中第一个未勾选 `- [ ]`（含 `DETECT-*`）。
2. 只消费已冻结且已标「可消费」的契约；宿主安装验收不是前端前置。
3. 独占目录：`sand-iam/sandadmin-artd/src/views/plugin/sand-iam/`（无书面交接不得改 Codex PHP）。
4. 有可消费缺口就自领 `U-xx`；无可做项则 WAITING + 1h backoff，**目标不灭**（禁止因 DETECT 空闲 `autopilot_ctl off`）。
5. 仅在需要云权限/密钥/付费/不可逆操作/产品决策时找用户。

## 启动 / 恢复

用户只说 **「恢复启动 Autopilot」** 即可。Agent 应立刻执行：

```bash
bash /Users/code/project/sand_plugins/.cursor/autopilot/start_goal.sh
```

## 当前状态

- 2026-08-14：Autopilot enabled。U-01～U-04 已完成。
- DETECT：WAITING（workspace fingerprint `4a98f5952ada3326`，backoff +1h 至 `2026-08-14T10:48:00+00:00`）。A-01 仍等 IAM-05（真实 signer + 已有后台会话）。不因空闲关闭 Goal。
