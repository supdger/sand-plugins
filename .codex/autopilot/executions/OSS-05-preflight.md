# OSS-05 外部与长跑门槛预备记录

- 日期：2026-09-12
- 范围：只修改权威验收工具、开发文档和非 PostgreSQL 回归；未连接外部对端，未同步宿主，
  未执行数据库、服务启停、提交、推送或部署。

## 当前完成的验收基础设施

- `validate-casdoor-comparison.php` 固定三条旅程、SandIAM/Casdoor 各两轮、同一独立开发者、
  同环境指纹、候选摘要、目标时间/操作数、安全/结果等价、零未解决失败、清理和逐轮证据 SHA-256。
  时间戳必须是可往返验证的 UTC 秒精度，非法日历日期不得由运行时静默归一化。
- 第三条旅程已从 SandAI 改为受控非 AI 文档处理服务；第一条通用示例从历史 `matter` 改为
  `work_item`，与当前公开业务示例一致。
- `run-endurance-acceptance.php` 固定最终候选身份和至少 86400 秒，要求 candidate/health/allow/
  deny/revoked/audit/metrics 七类 probe，记录独立 request ID、统一 acceptance run ID、p50/p95/p99、
  RSS/FD 最大值与斜率、队列、Worker、安全计数、event-loop 和 pool wait，并生成逐行哈希链。
- `verify-endurance-evidence.php` 从 JSONL 重新计算哈希链、24 小时墙钟/单调时长、采样间隔、
  request ID 唯一性、延迟/资源阈值和零容忍计数；隔离的 961 样本证据通过，篡改样本被拒绝。
- `prepare-external-acceptance.php` 只接受包外 `release/unsigned` manifest，自动绑定候选摘要并生成
  endurance/Casdoor/协议互操作/备份恢复/独立交付完整槽位；默认 host、阈值、reviewer、结果与证据字段故意无效，不能空模板过门。
  当前 review-only `candidate/dirty-not-release` manifest 已实测被拒，且没有创建输出文件。
- `validate-protocol-interop.php` 固定 OIDC、SAML、LDAP、SCIM、CAS、Kerberos/SPNEGO、RADIUS
  七类声明范围，要求版本化标准客户端、真实受控对端、独立复核、协议专属正负/撤销或重放断言、
  清理和不可复用的证据哈希；泛化 curl、缺失 CAS Ticket 重放拒绝、篡改证据、符号链接证据
  及含高置信私钥的证据均关闭失败。
- `validate-backup-recovery.php` 固定 PostgreSQL custom-format 归档、预建非生产隔离目标、不同源/恢复
  DSN 指纹、`--exit-on-error --single-transaction --no-owner --no-acl`，禁止 `--create/--clean`；
  逐项比较逻辑状态、迁移账本、撤销会话/凭证、审计链、允许/拒绝/验签、宿主和其他插件无损，
  并要求七类证据哈希。源/目标同库、危险 flags、撤销会话复活或缺失清理证据均关闭失败。
- `validate-independent-delivery.php` 要求未贡献代码、无 SandIAM 经验且未获开发者协助的参与者，
  只使用包内十二份公开材料（含贡献、漏洞报告和应用用户指南），在 fresh host 完成包验签、环境预检、全新安装、首次配置、人类业务应用、
  机器服务、失败恢复、卸载清理八步，并证明真实业务副作用、allow/deny/revoke、双侧审计和宿主无损。
  私下协助、内部文档、缺少机器服务或双侧审计均关闭失败。
- 24 小时发布阈值固定为错误率、Worker exit/restart、未授权放行、数据损坏和不可恢复积压零容忍；
  计划中的授权值只能从环境变量读取，响应正文不进入证据。
- 当前队列指标已绑定 Webhook、OIDC logout、Sync outbox 与 Sync run 的精确持久状态公式；目录 Sync outbox 拒绝、驱动异常和不可解密载荷会在有界尝试后进入可审计 failed，并有同应用人工恢复入口。该实现仍待 PostgreSQL、worker 和 24 小时实测。

## 当前验证

- non-PG/contract：114/114 PASS。
- PHP lint：507/507 PASS。
- 测试质量：tests=139、source_matches=0、heuristic_leads=1、exit=0；portal 与一次性秘密响应源码形状门禁已显式标记为静态规则测试并单独执行通过。
- 包完整性：24/24 PASS。
- 敏感序列化门禁：35/35 模型、61/61 隐藏字段实际 `toArray()`/JSON 验证通过；登记集合与源码中全部 `$hidden` 声明精确一致。
- 发布卫生：11/14；失败项为未获批的项目 `LICENSE`、具体私密漏洞报告入口，以及 DCO/CLA 二选一贡献机制。
- 当前依赖漏洞状态：未验证。Composer 官方 audit 因需要向 Packagist 发送锁定依赖名/版本而被安全审批拒绝；没有绕过。聚合目录中的 pnpm 调用因无根 lock 在发送前退出。官方查询的精确授权已列为 OSS-04 F。

## 不计通过的边界

本记录只证明验收计划和证据门禁会关闭失败。没有实际 24 小时采样、Casdoor 或 SandIAM 双方
十二轮旅程、七类外部标准客户端/真实对端运行、实际备份恢复、独立开发者公开文档交付、真实宿主或业务数据，因此 R09、F/L/D 和发布门槛
均不增加。实际执行仍等待用户许可证决定、首链数据库/宿主/服务授权以及外部人员和对端。
