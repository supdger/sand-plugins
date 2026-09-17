# SandIAM 数据库审计跟进

本页记录 schema 审计建议进入当前交付或后续版本的边界。审计建议不能直接替代表归属、
公开接口、迁移兼容性和真实业务证据。

## 当前 0.7.3

- `sand_iam_policy.action` 与公开应用动作统一为 96 字符。
- `identity_role`、`identity_user_type` 和 policy 目标增加同一应用的数据库组合约束。
- policy version、rollback 来源和 published 指针增加同策略、同应用的数据库组合约束。
- 保留“不可变归档副本”产品语义；修复 purge 误用软删除的问题，并覆盖历史软删归档。

这些修复均由新增迁移 041 完成，不改写已发布的 001–040。迁移发现存量归属冲突时关闭失败，
不删除、换绑或猜测修复业务数据。

## 后续版本评审

- `provisioning_event` 是否退役。
- `directory_sync_run` 与 `sync_run` 是否合并。
- SCIM group 与本地 identity group 的映射模型。
- 86 张持久表的数据字典和注释生成方式。

上述项目涉及公开模型、历史数据或运维契约，需要分别形成需求、迁移与兼容设计。当前版本不删表、
不合并业务事实，也不以审计建议替代产品决策。
