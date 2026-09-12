# SandIAM 终极宿主与发布验收执行单

> 本文是执行顺序，不是完成记录。每项必须附命令/请求/浏览器截图或数据库计数；静态、数据库、HTTP、浏览器、业务联调、部署和线上验证分别下结论。

> 本文不固定候选号、当前哈希或完成分数，避免执行说明与事实记录漂移。当前唯一候选身份、
> 48 项计分、恢复状态和未通过门槛以[终极验收原子账本](sand-iam-terminal-acceptance-ledger.md)
> 与[任务看板](sand-iam-task-board.md)为准。历史候选和历史宿主观察不得自动计入当前通过。

> **历史宿主记录，不作当前结论：** 早期受控同步后曾观察到 42/42 worker、未登录 401、未启用 503 和前端 200；“宿主 SandIAM 表为 0”也是事故当时的瞬时状态。当前可复核的宿主事实以 [`HOST-202609-001`](../../../docs/host-requests/HOST-202609-001-sandpackage-failed-recovery.md) 为准：它仍是 `local draft / not sent`，demo registry 为健康的 `0.7.0`（`state=1`、`stage=completed`）；历史 `state=8` 只在 quarantine 证据中。实际恢复仍为 2/8，禁止以空结构重装替代恢复；当前 Codex 可控 browser context 不可用，不能把阻塞归咎为用户未登录。

## 1. 执行前授权与隔离

- 本计划不创建或删除数据库。数据库验收只可在用户明确指定的既有受控数据库中进行；若某项
  必须依赖全新空库，则等待用户或外部管理员提供预创建环境，不得由安装器、迁移、测试工具
  或运行时隐式创建；
- 明确允许读取和修改 SandIAM 根/插件生命周期 SQL；
- 宿主同步、进程重启、`.env` 密钥写入、业务仓库修改、真实外部身份源连接和部署分别授权；
- 不使用生产库、生产密钥、真实用户隐私或真实业务数据做验收。

## 2. 权威源码和包一致性

| 检查 | 通过条件 |
| --- | --- |
| 根/插件迁移 | 当前 `001–038` 共有 38 个修订号、39 个迁移文件；根/插件同名迁移逐个 SHA-256 一致，两个 `006` 为不同内容的独立迁移；021 固定为已发布 0.6.0 哈希，034 仅补三项用户组角色权限且不得自动授予既有角色，035 的文件名/修订号/hash/包版本账本必须在结构指纹通过后才收养，036–038 的规范化自校验值必须精确匹配，037 增加草稿/修订表且新权限不得自动授予角色，038 只增加认证限流过期清理索引 |
| lifecycle | 根与插件 install/update/uninstall 实际展开结果一致；包含表、约束、索引、菜单和权限；卸载逆序清理 |
| 元数据 | 根/插件 `info.ini` 版本一致，README 状态不超过证据 |
| 包内容 | PHP 依赖、管理端载荷、运行面静态资源、SDK、文档和迁移均进入发布包；根/插件 `recovery/failed-upgrade.v2.json` 必须字节一致、递归 canonical、内联受限 profile，并绑定 descriptor-excluded 的规范化载荷摘要及根 `update.sql` 摘要；不含 node_modules、测试密钥和本机路径 |
| PostgreSQL | 无 `AUTO_INCREMENT`、`UNSIGNED`、`ENGINE=`、反引号或新增 `sa_*` 业务表 |

## 3. 既有受控 PostgreSQL 环境中的生命周期

取得本任务精确授权后按以下顺序运行。任何一步失败立即保留日志、停止后续写入并清理本次
插件夹具；不得创建、删除或重建数据库：

1. 空库执行根 `install.sql`；记录 SandIAM 表/约束/索引/菜单/权限实际数量；
2. 执行两次根 `update.sql`，证明幂等；
3. 运行 T01–T06 所有真实 ORM 集成测试；
4. 从 `0.1`、`0.2`、`003`、`005`、`007` 边界分别升级到当前，核对回填、唯一约束与跨应用拒绝；
5. Webhook 两 worker 并发 `SKIP LOCKED`，同一投递只能被一个 worker 领取；
6. 执行根 `uninstall.sql` 两次，SandIAM 表、菜单和权限残留均为 0；
7. 清理本次 SandIAM 夹具并核对宿主及其他插件未受损；数据库本身保持不变。

不能用事务回滚替代 fresh install/update/uninstall；业务测试可以在事务中回滚，但生命周期必须真实提交。

## 4. 真实宿主 HTTP

受控同步到 `/Users/code/project/sand_plugins/sandadmin-demo-host` 后完整重启演示宿主（服务端为其 `server/` 子目录），再验证。`/Users/code/project/sandadmin` 保持纯净通用宿主，不用于插件演示：

- 注册 → 验证 → 登录 → 刷新 → 会话 → 改密全撤销 → 新密码登录 → 退出；
- TOTP、恢复码、Passkey 真实浏览器仪式；
- OAuth/OIDC discovery、JWKS、Code+PKCE、userinfo、refresh rotation、revoke、logout；
- 外部 OIDC/OAuth、SAML、LDAP、SCIM 使用标准客户端/测试服务，不只调用内部 PHP 方法；
- API 决策和 Webman 中间件允许/拒绝、audience/scope、路由冲突和数据范围；
- Webhook 真实 HTTPS 接收端验证原始 body HMAC、时间窗、事件 ID 去重、失败重试、密钥轮换；
- 审计导出下载、31 天/10,000 条限制、CSV 注入防护和 no-store。

每条请求保存调用方和 SandIAM 共同的 `X-Request-Id`。证据中不出现密码、验证码、完整 token、TOTP seed、恢复码、client secret、Webhook secret 或身份源密钥。

## 5. 三角色浏览器

分别使用平台管理员、客户主体/应用管理员、独立应用用户会话，在 `1440×900` 与 `1280×720` 验收：

- 平台管理员：创建客户主体/应用、委派、全局目录、审计；
- 应用管理员：只看到获授应用，配置认证、身份源、用户/角色/策略、OAuth 客户端、API、Webhook；撤权后刷新即失效；
- 应用用户：不进入 SandAdmin，完成资料、密码、会话、MFA、Passkey、外部账号连接；
- 默认列表只显示业务名称和可操作状态；ID/代码为辅助，JSON 不平铺；
- 加载、空、失败、无权、停用、冲突和功能未配置状态可区分并可恢复。

测试夹具要记录创建清单和清理清单；浏览器截图不能代替数据库/审计清理证明。

## 6. 两个真实业务接入

### 非 AI 机器调用服务

- 受控机器服务幂等登记 service/action，不依赖 SandAI；
- 获授 workload client 调用一个真实、有可观察业务副作用的服务动作成功；未授权、错误
  audience/action、过期、重放和撤销凭证均失败；
- SandIAM 与机器服务双侧审计使用同一请求标识关联，最后撤销授权并清理业务副作用。

### 非 AI 业务应用

优先使用一个受控接入应用的真实业务详情或列表接口：

- PHP/TypeScript SDK 完成登录和接口代码授权；
- 业务后端对 list/read/write/export 使用真实业务属性执行 scope；
- 同手机号在另一应用独立注册不串账号、不共享登录；
- 前端隐藏按钮只作体验，直接请求业务 API 仍由后端拒绝。

## 7. 安全、并发与恢复

- 密码、验证码、token、恢复码、OAuth/Webhook/身份源秘密的库/消息/日志/审计/导出泄露扫描为 0；
- 登录、验证码、MFA、密码重置、OAuth、SCIM、Webhook 管理动作的限流与重放拒绝；
- 并发刷新、授权码消费、SCIM upsert、身份绑定、应用委派撤权和 Webhook 抢占；
- OAuth、MFA、Webhook 和 federation keyring 轮换期间旧密文/旧签名可按窗口验证，窗口结束后拒绝；
- PostgreSQL 备份恢复到新临时库后，授权、会话撤销、审计链和验签仍一致；
- 关闭 SandIAM 或移除 required plugin 时，机器服务与业务接口 fail closed，不降级放行。

## 8. 发布结论模板

### 24 小时连续稳定性

最终候选须按[24 小时连续稳定性验收](sand-iam-endurance-acceptance.md)绑定 ZIP、manifest 和干净
revision 后连续采样至少 `86400` 秒。不得拼接短跑或跨候选结果；健康、允许、拒绝、撤销、审计、
候选身份和资源指标七类 probe 必须持续通过。该 runner 不启动服务，所需同步、启停和受控验收
写入仍按本执行单单独授权。

最终报告固定分开：

```text
需求/架构/票据：通过项/总项
模块实现：通过项/总项
正式 FLOW 验收：通过项/总项
本地业务闭环：通过项/总项
可上线部署：通过项/总项
已部署：是/否
线上验证：通过/未执行/失败
残留与回滚：数据库、进程、配置、测试账号、文件各自结果
```

只有所有适用项都有当前证据，才可写“SandIAM 终极目标完成”。
