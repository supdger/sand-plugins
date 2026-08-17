# Cursor Autopilot task queue

> **执行真相源。** 每回合：先干第一个 `- [ ]`；空队列时保留 `DETECT-*` 持续监视（对齐 Codex，不等人说继续）。
> Codex 队列：`.codex/autopilot/tasks.md`（IAM-01 ▶）。未冻结字段不得猜测。

## Cursor frontend

- [x] U-01 · 管理端页面目录与诚实占位页
  - 验收：organization / application / environment / workload-client / service-grant / audit / 总览均有占位页；不猜测 DTO；不改 `plugin/sand-iam/`。
  - 执行记录：`.cursor/autopilot/executions/U-01.md`

- [x] U-02 · SandIAM 管理端冻结 DTO 接入
  - 前置：IAM-01 已冻结，管理 API 字段字典可消费。
  - 范围：仅 `sand-iam/sandadmin-artd/src/views/plugin/sand-iam/**`；实现 organization、application、environment、workload-client、service-grant、audit 的加载、空、失败状态和已冻结字段展示，不改 PHP、SQL、路由、权限码或未冻结字段。
  - 验收：管理端类型检查和构建通过；页面不伪造 CRUD、不猜测字段；执行记录列出消费的契约版本、变更文件和验证结果。
  - 执行记录：`.cursor/autopilot/executions/U-02.md`

- [x] U-03 · SandIAM 管理端筛选、权限与错误交互
  - 前置：U-02 完成；[管理端接口交接 v0.1](../../sand-iam/docs/development/sand-iam-management-api-v0.1.md) 已标可消费。
  - 范围：仅 `sand-iam/sandadmin-artd/src/views/plugin/sand-iam/**`。已有列表补齐筛选/权限/稳定错误码；并接入 identity、identity-binding、user-type、role、resource、policy、admin-organization-grant、identity-role、identity-user-type。不改 PHP/SQL，不猜测未冻结字段，不伪称 IAM-05/A-01 成功路径。
  - 验收：筛选参数与契约一致；无列表权限或 403/5xx 为诚实失败而非空成功；`vue-tsc --noEmit` 与宿主构建通过。
  - 执行记录：`.cursor/autopilot/executions/U-03.md`

- [x] U-04 · 冻结管理写入面（save/update/disable 与额外操作）
  - 前置：管理 API v0.1 与 P0 第 4 节已冻结可消费；不依赖 IAM-05 signer。
  - 范围：仅 `sand-iam/sandadmin-artd/src/views/plugin/sand-iam/**`。为已冻结资源补齐创建/更新/停用；policy publish/revoke；grant revoke；identity-role / identity-user-type grant/revoke；补 service / action / credential 页。凭证明文只展示一次。错误走稳定错误码。不改 PHP/SQL。
  - 验收：表单只提交冻结字段；前端校验 condition/scope 的 equals/in；权限按钮按权限码显隐；`vue-tsc --noEmit` 与宿主构建通过。
  - 执行记录：`.cursor/autopilot/executions/U-04.md`

- [ ] DETECT-01 · 监视看板/契约/Codex 队列并自领 Cursor 项
  - 验收：每回合重读看板、IAM-01 契约文件、`.codex/autopilot/tasks.md`；有可消费冻结面就在 DETECT 上方追加 `- [ ] U-xx`；无可做项则更新 `.cursor/autopilot/detect-status.md`（WAITING，不是 BLOCKED）。
  - 执行记录：`.cursor/autopilot/executions/DETECT-01.md`
