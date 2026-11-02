## Why

SandIAM 当前应用策略由自研匹配与冲突循环裁决，未实际使用成熟 Casbin 内核。用户要求直接接入 Casbin 并取代这部分，同时保留现有身份、版本和数据范围契约。

## What Changes

- 固定官方 PHP-Casbin 与完整 Composer lock/发行依赖，普通 Enforcer 成为唯一应用策略判定内核。
- 现有 PostgreSQL 已发布版本经只读 Adapter 加载；同连接短 REPEATABLE READ READ ONLY 快照读取状态和关系。
- 保持最小 priority 层、同层 deny 优先、最小 policy.id 的单 scope；条件仅扩展严格 equals/in。
- 模拟使用同模型全规则判定与逐规则解释，异常默认拒绝；新增实际模型、行为基线和 PostgreSQL 回归。

## Capabilities

### New Capabilities

- `iam-casbin-policy-engine`: 实际 Casbin 判定、不可变发布快照、模拟解释与依赖载荷。

### Modified Capabilities

无。

## Impact

仅 SandIAM 后端、依赖、打包契约、相关测试与文档；管理端和机器服务凭据/grant 不改。无需 schema 迁移，不同步宿主、不提交推送或发布。
