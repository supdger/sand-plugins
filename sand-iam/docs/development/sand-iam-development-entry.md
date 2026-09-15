# SandIAM 开发入口

> 状态：P0 契约已由 Codex IAM-01 于 2026-08-13 冻结；2026-08-21 已增加[终极产品目标](../product/sand-iam-terminal-product-goal.md)与[终极验收矩阵](sand-iam-terminal-acceptance.md)。P0 是已实现基础，不是最终产品范围。
>
> 实时状态见 [任务看板](sand-iam-task-board.md)；责任边界见 [协作约定](sand-iam-pg-collaboration.md)；冻结内容见 [P0 契约](sand-iam-p0-contract.md)。
>
> IAM-T05 的稳定接口代码、路由绑定、Webman 中间件和 SDK 约定见[接口治理与业务接入契约](sand-iam-api-governance-v0.1.md)。

## 已冻结

- 源码路径：`/Users/code/project/sand_plugins/sand-iam`；演示与验收宿主：`/Users/code/project/sand_plugins/sandadmin-demo-host`（服务端为其 `server/` 子目录）；`/Users/code/project/sandadmin` 保持纯净通用宿主，不用于插件演示；
- 插件根目录：`sand-iam`；插件后端目录：`plugin/sand-iam`；
- Codex / Cursor 目录独占与并行规则（见协作约定）；
- 显示名：`SandIAM`；PHP 命名空间：`plugin\\SandIam`；
- PostgreSQL 表前缀：`sand_iam_*`；
- 能力边界：Casdoor 式身份/组织/应用管理、Casbin 式策略判定、统一数据范围与审计；
- SandIAM 提供通用能力，应用配置自己的用户类型、资源和策略；
- SandAI 消费 SandIAM 调用上下文，不能再创建平行应用、凭证或服务授权体系。

## P0 开发契约门槛

### IAM-T09 / F03 门户 Captcha 配置（2026-09-13）

- 归属 SandIAM，沿用 `sand-iam` / `SandIam` 与现有应用、认证策略、消息服务挂载；不新增表、主键或迁移。
- 公开入口 `GET /api/sand-iam/v1/auth/captcha/config`，参数为组织/应用代码及 `action=login|register`；复用认证服务的有效组织/应用、网络策略、认证策略和体验方法校验，不依赖品牌体验记录存在。
- 不使用后台账号或新增管理权限码。配置响应 `Cache-Control: no-store`、`Pragma: no-cache`，只返回 `required:false`，或 `required:true,available:true,widget` 四字段公开挑战；没有可用供应商时 `required:true,available:false`。组织/应用、网络、方法或参数拒绝保留既有错误语义。
- 挑战与应用、用途绑定；秘密、驱动类和完整供应商配置不进入公开响应。门户配置失败时不能跳过验证，提交消费后重新挑战；应用切换销毁旧组件及回调。
- 验收覆盖关闭/启用策略、不可用供应商、隔离拒绝、品牌体验缺失、登录/注册挑战提交、过期与失败恢复。离线验证与真实供应商/浏览器验收分开计数。

开始建表和代码前，已在 [P0 契约](sand-iam-p0-contract.md) 冻结：

1. 领域模型：身份目录/身份源绑定、组织、应用、环境、工作负载客户端、服务、服务授权、策略与审计；
2. 每张表的主键、外键、唯一约束、组织/应用边界与 PostgreSQL 迁移；
3. 管理 API、运行时身份上下文 API、权限代码与稳定错误码；
4. SandAI Adapter：调用方、环境、audience、service action、有效期与拒绝行为；
5. 最小验收：安装、升级、卸载；独立应用用户不使用宿主后台账号；策略和数据范围在读取与写入操作上均生效。

接入应用的具体用户类型和业务规则不属于该门槛；它们只是在 P0 能力完成后配置为用户类型与策略。
