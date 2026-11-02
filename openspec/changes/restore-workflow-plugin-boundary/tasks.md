## 1. 恢复通用提供方

- [x] 1.1 固定 6.1 专用改造范围和恢复基线，核对涉及的源码版本、未提交差异、发布与运行副本状态，记录仍缺失的运行证据。
- [x] 1.2 恢复 SandWorkflow 与受影响插件/框架的专用配合变更，审查最终 diff 保留无关修改，并运行既有通用契约回归。

## 2. 消费端集成

- [ ] 2.1 修订 sand_ai 的 unify-sandai-product 6.1 为 SandAI 消费既有 Workflow 公共能力，核对规格与权威交付源码一致。
- [ ] 2.2 实现并验证消费端明确请求 start/detail、可信 context 与不自动触发业务动作的边界；另按 6.1 验证流程调用已发布 AI 服务、原幂等键恢复、当前授权读取和来源费用追溯，未验证不得以 start/detail 适配存在代替完整集成。

## 3. 规则与验收

- [x] 3.1 在隔离候选准备 sand_plugins 规则及 SandAdmin 入口，验证正常消费、通用性、必要性、证据缺失及回滚例外边界，经独立审查后精确应用并比对。
- [ ] 3.2 对恢复与消费端结果做独立验收，记录源码检查与真实宿主验证的区别，未验证项不得勾选为完成；运行 OpenSpec strict validation。

## 验证范围（2026-09-28）

1.1/1.2 完成的是源码恢复与配合调整：Workflow 45 项 PHP 语法检查、26 项通用契约回归、Reflection 权威来源、包完整性及 3 份受控镜像核对通过，独立审查通过。恢复差异保留无关工作；未执行运行数据库迁移或部署。临时证据：[恢复说明](/private/tmp/workflow-rollback-audit-20260928/ROLLBACK.md)、[恢复后核对](/private/tmp/workflow-rollback-audit-20260928/final-postimage-check.json)、[包检查配合调整](/private/tmp/workflow-rollback-audit-20260928/package-support-sync-result.json)。

3.1 的 V2 规则候选经独立审查后应用，9/9 文件前置与应用后 SHA 核验通过，正常、拒绝、必要性与回滚例外边界保持一致。临时证据：[候选清单](/private/tmp/sand-cross-plugin-rule-candidate/manifest.json)、[边界核验](/private/tmp/sand-cross-plugin-rule-candidate/boundary-review.md)。本次仅更新任务状态，未修改独审规则或规格文本；OpenSpec strict validation 通过。

消费端 start/detail、回滚、文件状态、用量及排队授权回归分别通过 22/11/11/23/9 项；来源追溯测试存在基线同样缺少 fixture 的失败。真实调用方、数据库业务链和原 6.1 完整场景仍未验收，2.1/2.2/3.2 保持未完成；这些源码检查不代替真实业务验收。
