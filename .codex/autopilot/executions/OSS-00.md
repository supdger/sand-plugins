# OSS-00 · 完整开源成品 Goal 基线

## 范围

- 只读重建当前 SandIAM、SandAdmin lock、候选包内一致性和旧循环状态。
- 修正现有 README、任务板和验收账本；不建立平行计分体系。
- 不改 PHP、SQL、Vue/TS，不接触数据库、宿主同步、服务、提交、推送或部署。

## 当前证据

- 仓库 HEAD：`88ae1a0702e597f52b39cf44f0590112bf0f8189`
- SandIAM tree：`24af41dd869e2ac64fcc7b71ea64e255dcda86a4`
- SandAdmin H1 lock：`558d92959947230ee562f29e015c62566be58c8e`
- `php sand-iam/tools/check-package-integrity.php`：`23/23 PASS`
- review-only package digest：`be46b1d651a7f63269959259e472b718df4c1b85b4231557520d95f3f54de566`
- 开源发布材料缺口：未发现 LICENSE、NOTICE、SECURITY、CONTRIBUTING、SBOM/第三方许可证清单。
- 旧 Codex 与 Cursor Autopilot/DETECT：均已关闭。
- 2026-09-12 再核：Codex 任务列表中 `/Users/code/project/sand_plugins` 只有本 Goal 为 `active`，旧 SandIAM 任务均为 `notLoaded`；`/Users/supdger/.codex/automations/**/automation.toml` 无 `SandIAM`、`sand-iam` 或 `sand_plugins` 命中。只读复核，未归档、暂停或修改任务/自动化。

## 结论

- 2026-09-08 的 28/48 仅作为可继承证据候选；当前 Goal 从逐原子复核 0/48 开始。
- 发布门槛单列为 0/10，不并入 FLOW 分母。
- 当前包仅通过内部一致性，仓库整体 dirty，不可发布。
