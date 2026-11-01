# SandIAM 开发入口

> 状态：P0 契约已由 Codex IAM-01 于 2026-08-13 冻结；2026-08-21 已增加[终极产品目标](../product/sand-iam-terminal-product-goal.md)与[终极验收矩阵](sand-iam-terminal-acceptance.md)。P0 是已实现基础，不是最终产品范围。
>
> 实时状态见 [任务看板](sand-iam-task-board.md)；责任边界见 [协作约定](sand-iam-pg-collaboration.md)；冻结内容见 [P0 契约](sand-iam-p0-contract.md)。
>
> IAM-T05 的稳定接口代码、路由绑定、Webman 中间件和 SDK 约定见[接口治理与业务接入契约](sand-iam-api-governance-v0.1.md)。

## 已冻结

- 源码路径：`/Users/code/project/sand_plugins/sand-iam`；演示与验收宿主：`/Users/code/project/sand_demo`（服务端为其 `server/` 子目录）；`/Users/code/project/sandadmin` 保持纯净通用宿主，不用于插件演示；
- 插件根目录：`sand-iam`；插件后端目录：`plugin/sand-iam`；
- Codex / Cursor 目录独占与并行规则（见协作约定）；
- 显示名：`SandIAM`；PHP 命名空间：`plugin\\SandIam`；
- PostgreSQL 表前缀：`sand_iam_*`；
- 能力边界：Casdoor 式身份/组织/应用管理、官方 PHP-Casbin 应用策略判定、统一数据范围与审计；
- SandIAM 提供通用能力，应用配置自己的用户类型、资源和策略；
- SandAI 消费 SandIAM 调用上下文，不能再创建平行应用、凭证或服务授权体系。

### IAM-06 服务目录只读预检（2026-09-25）

- 归属 SandIAM 现有 `plugin\SandIam\app\runtime\ServiceCatalog`；不增加表、路由、权限码或迁移。插件通过宿主本地端口传入与 `registerServiceActions` 相同的服务及动作声明，不直接读取 SandIAM 模型或表。
- `inspectServiceActions(array $service, array $actions): array` 复用登记声明校验，返回 `service_code`、`service_status`（`missing|active|disabled`）、按所请求动作代码映射的 `action_statuses`（同三状态）及 `can_register`。仅当服务与所有动作均未停用时为可登记；缺失项允许管理员显式登记。
- 预检只读、不创建服务、动作、应用、客户端、凭证或 grant；不改变停用状态，也不替代正式登记事务中的并发复核。宿主端口缺失或读取失败时消费者关闭失败并展示处理入口。正式登记继续由 `registerServiceActions` 在行锁事务内拒绝停用项。
- 验收覆盖全缺失、已登记、服务停用、动作停用、声明无效、无写入，以及预检后停用造成的登记拒绝。真实 SandIAM/PostgreSQL/页面和 SandAI 整链单独留证。

### 工作负载客户端只读资源引用（2026-09-25）

- 归属 SandIAM 现有 `sand_iam_workload_client`、`sand_iam_environment`、`sand_iam_application`、`sand_iam_organization`；各表主键仍为 `id`，不增加表、字段、迁移、路由或管理权限码。宿主本地端口 `WorkloadClientReferenceVerifier::verifyForService(int $workloadClientId)` 接受正整数客户端 ID，返回 `organization_id`、`application_id`、`environment_id`、`workload_client_id` 和 `status=1`。
- 只读端口从 SandIAM 当前持久记录核实四级关系均启用且未删除；缺失、停用或已删除时拒绝，不接受消费者自报组织/应用/环境归属，也不返回凭证或客户端密钥。SandAI 在文件尚未创建的上传授权中以此作为工作负载客户端资源事实，SandIAM 正式调用授权再与已验证 context 的四级归属比较。
- 验收覆盖有效引用、四级缺失/停用/软删除、非法 ID 和无写入。源码及无数据库回归不代替真实 PostgreSQL/SandIAM 调用与安装验收。

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

## 应用策略内核接入（0.8.3 源码候选）

归属 SandIAM，沿用 sand_iam_policy / sand_iam_policy_version 与公开授权、simulate、entity scope guard 契约；不新增表、路由或权限；范围检查审计使用下述迁移 043。固定 casbin/casbin 4.5.0、普通 Enforcer、只读 Adapter；runtime 和 simulate 共用 plugin/sand-iam/resources/casbin/application-policy.conf。条款从同 PDO 的短 REPEATABLE READ READ ONLY 快照读取，禁止外层旧事务冒充新快照；发布事务与执行阶段实体实时校验独立。已有应用、身份、角色、组与发布指针复用，非法或跨应用主体拒绝且记录 policy/version。条件的 equals/in 原语调用官方 Symfony ExpressionLanguage ===/in；只保留既有 JSON 形状和缺键语义桥接，不开放任意表达式。PolicyAuthorizer 保留公开薄门面，原自研主体匹配、角色遍历、优先级效果及模拟裁决已删除。机器服务 grant、凭据、网络、配额与执行前 revalidation 保持原职责。

本次对应 OpenSpec integrate-casbin-policy-engine；正式交付须锁定 vendor/lock/model 与 SBOM、许可，区分源码、真实 Enforcer、PG、ZIP 与实际安装宿主证据。

### 范围检查审计事件键（0.8.3 源码候选）

归属 SandIAM 现有 `sand_iam_audit_log`，主键、组织/应用归属和公开权限不变。新增迁移 `043_scope_audit_event_key.pgsql`：`event_key varchar(64) NOT NULL DEFAULT ''`，仅允许空或 64 位小写十六进制，并以 `(request_id, action, event_key)` 唯一约束替换原两字段约束。旧审计事实不改写；非 scope 审计保持空键及原请求/动作去重。scope 检查以版本化稳定 SHA-256 区分语义事件，重复事件不再发布副作用；原始可信属性不落入审计。0.8.3 的旧 43 条升级先精确准入，再同事务执行原 043，形成 44 条账本；已在 44 条的 0.8.1 / 0.8.2 升级只核对完整账本和六组捕获结构，不执行 DDL 或重放 043；接入已有 schema 只接受目标 44 条账本及真实捕获的新结构指纹。
