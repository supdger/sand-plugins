# SandIAM 开发入口

> 状态：P0 契约已由 Codex IAM-01 于 2026-08-13 冻结为可消费 v0.1；插件骨架与 Cursor/Codex 协作约定已创建。
>
> 实时状态见 [任务看板](sand-iam-task-board.md)；责任边界见 [协作约定](sand-iam-pg-collaboration.md)；冻结内容见 [P0 契约](sand-iam-p0-contract.md)。

## 已冻结

- 源码路径：`/Users/code/project/sand_plugins/sand-iam`；验收宿主：`/Users/code/project/sandadmin`；
- 插件根目录：`sand-iam`；插件后端目录：`plugin/sand-iam`；
- Codex / Cursor 目录独占与并行规则（见协作约定）；
- 显示名：`SandIAM`；PHP 命名空间：`plugin\\SandIam`；
- PostgreSQL 表前缀：`sand_iam_*`；
- 能力边界：Casdoor 式身份/组织/应用管理、Casbin 式策略判定、统一数据范围与审计；
- SandIAM 提供通用能力，应用配置自己的用户类型、资源和策略；
- SandAI 消费 SandIAM 调用上下文，不能再创建平行应用、凭证或服务授权体系。

## P0 开发契约门槛

开始建表和代码前，已在 [P0 契约](sand-iam-p0-contract.md) 冻结：

1. 领域模型：身份目录/身份源绑定、组织、应用、环境、工作负载客户端、服务、服务授权、策略与审计；
2. 每张表的主键、外键、唯一约束、组织/应用边界与 PostgreSQL 迁移；
3. 管理 API、运行时身份上下文 API、权限代码与稳定错误码；
4. SandAI Adapter：调用方、环境、audience、service action、有效期与拒绝行为；
5. 最小验收：安装、升级、卸载；独立应用用户不使用宿主后台账号；策略和数据范围在读取与写入操作上均生效。

律序的律师/客户具体业务规则不属于该门槛；它们只是在 P0 能力完成后配置为用户类型与策略。
