## Why

SandAI 可选 Workflow 集成把 AI 专用服务协议放入通用工作流插件，尚无必须修改提供方的验证依据。需要恢复插件边界，并建立跨插件变更论证规则。

## What Changes

- 恢复 SandWorkflow 通用功能，撤回为 SandAI 6.1 增加的专用实现，并核对受影响插件与框架的配合变更。
- SandAI 通过 SandWorkflow 既有公开能力推进自身集成；start/detail 仅响应调用方明确请求和可信上下文，不以分析成功自动触发业务动作。业务流程调用已发布 SandAI 服务的完整需求继续在 unify-sandai-product 6.1 验证，不能用适配入口存在代替完成证据。
- 在 sand_plugins 明确跨插件变更论证条件，SandAdmin 入口引用同一规则。
- **BREAKING**：撤回未正式交付的 6.1 专用服务节点与相关候选契约；正式发布状态须在执行时核实，存在已部署数据时另列迁移方案，不能直接删除。

## Capabilities

### New Capabilities

- `plugin-consumption-boundary`: 消费端集成、通用 Workflow 恢复与跨插件变更论证。

### Modified Capabilities

无。

## Impact

权威源码范围为 sand_plugins 的 SandWorkflow、SandAI 及经证据确定的相关基础包；SandAdmin 只承担必要的框架配合调整与治理入口。sand_ai 的 unify-sandai-product 6.1 同步修订为消费端集成。实际数据库迁移、服务启停、宿主同步、提交、推送和部署不由本变更文档自动授权。
