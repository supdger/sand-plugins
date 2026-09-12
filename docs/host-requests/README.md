# 宿主问题请求

这里保存从插件开发或 demo 验收中发现的 SandAdmin 宿主/兼容性问题。报告使用 [模板](TEMPLATE.md)，冻结宿主与插件版本组合后交给 SandAdmin 接受；不得以报告宿主问题为由直接修改 `/Users/code/project/sandadmin`。

单插件复现默认在插件侧解决；只有零插件基线、中立扩展或多个独立插件复现的通用缺陷才提交 SandAdmin。宿主方案应尽量贴近 SaiAdmin 上游，优先扩展契约而非核心分叉。

当前请求：

- [HOST-202609-001：SandPackage 通用失败升级恢复与提交后部署续行](HOST-202609-001-sandpackage-failed-recovery.md)（`local draft / not sent`；SandIAM 仅提供冻结 demo consumer 证据，目标为多插件通用宿主契约）

每轮诊断只允许改变宿主或插件之一。前两轮不能缩小范围时，第 3 轮使用 `gpt-6-astra/high` 并记录实际任务模型证据；仍无根因或下一项可区分实验时，标记 `BLOCKED_ROOT_CAUSE_UNKNOWN`，保存证据并停止，不自动开始第 4 轮。
