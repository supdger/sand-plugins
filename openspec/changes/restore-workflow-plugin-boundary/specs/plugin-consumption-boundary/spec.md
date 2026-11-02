## Purpose

规定 SandAI 对通用工作流插件的消费边界，以及插件开发中修改其他提供方前所需的证据与审查，确保各插件独立维护自身领域、现有通用能力能够继续使用且集成改造可核验。

## ADDED Requirements

### Requirement: 通用工作流独立使用

SandWorkflow SHALL 保持流程定义、启动、审批和查询等既有通用契约，不因 SandAI 可选集成而要求 SandAI 专用服务协议或 AI 成果结构。

#### Scenario: 未安装 SandAI

- **WHEN** 消费者通过既有受支持方式使用 SandWorkflow 且未安装 SandAI
- **THEN** 既有工作流功能不因缺少 SandAI 而失效

### Requirement: SandAI 消费端集成

SandAI SHALL 通过 SandWorkflow 既有公开能力实现可选集成，并在自身领域维护 AI 任务、失败恢复和成果解释。

#### Scenario: 调用方明确请求使用工作流

- **WHEN** 调用方明确请求启动（start）或查询（detail）工作流，并显式提供经服务端认证与授权确认的可信调用上下文
- **THEN** 消费端适配按既有公开契约执行该请求；这不表示已实现“业务流程调用已发布 SandAI 服务”的完整业务链

#### Scenario: 分析结果不隐式触发业务动作

- **WHEN** 材料分析成功或返回 review_required，且调用方没有明确请求工作流动作
- **THEN** 不自动启动流程、审批或其他业务动作，材料分析服务仍可独立运行

### Requirement: 跨插件变更有据可审

插件开发引起其他插件或基础包改造前 MUST 由 sand_plugins 记录通用性或必要性证据并经未参与实现的审查者通过，专用业务不转移给通用提供方。

#### Scenario: 既有接口足够

- **WHEN** 最小验证证明公开 API、扩展点或配置可实现消费端需求
- **THEN** 消费端使用既有能力，不因此增加提供方专用改造

#### Scenario: 单一消费者确有必要

- **WHEN** 已确认自身功能有可复现的提供方能力缺口，替代方案无法满足，且最小契约变更经 sand_plugins 独立审查通过
- **THEN** 可实施该最小提供方变更，专用业务仍保留在消费端

#### Scenario: 证据不足

- **WHEN** 改造申请只有便利性理由或无法核实的验证结果
- **THEN** 结论保持未通过，不实施提供方改造
