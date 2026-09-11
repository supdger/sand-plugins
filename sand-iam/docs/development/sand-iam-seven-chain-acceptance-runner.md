# SandIAM 七条业务链验收编排器

`tools/run-terminal-acceptance.php` 是七条业务链的唯一可重复入口。它统一输出通过数、环境前置、夹具标识和清理结果；它不把源码检查或内存模拟说成真实宿主验收。

## 安全边界

- 默认 `preflight` 只运行已有非 PostgreSQL源码契约测试；不连接数据库、不启动服务、不访问宿主。
- `simulated` 在上述检查之上运行内存式提供方模拟。它会实际记录一次调用、副作用、允许/拒绝、撤销或重试，再验证清理；仍不接触真实 HTTP、浏览器或数据库。
- 所有模拟夹具使用固定格式的前缀。live 前缀必须精确匹配 `^sand_iam_acceptance_[a-f0-9]{16}_$`；真实执行必须使用本轮唯一的 16 位小写十六进制标识，例如 `sand_iam_acceptance_20260829a1b2c3d4_`。基础前缀、嵌套前缀、短/长标识、大写和非十六进制字符一律拒绝。计划中的清理、状态和对象创建请求编号都必须以同一个完整 `${prefix}` 开头，避免不同轮次共用幂等记录或相互匹配。
- `live` 模式默认拒绝执行。仓库内置 [`terminal-acceptance-live-driver.php`](../../tools/terminal-acceptance-live-driver.php)，但只有同时设置 `SAND_IAM_ACCEPTANCE_LIVE=1`、`SAND_IAM_ACCEPTANCE_ALLOW_DATABASE_WRITES=1` 和确认短语后才会调用真实 HTTP；它不创建数据库、不执行 SQL、不启动服务。
- live driver 读取固定夹具前缀、受控 JSON 计划和每条链明确需要的独立凭证槽：平台管理员、范围内管理员、范围外管理员、应用用户、服务客户端。只有 `sandiam` target 才会附对应凭证；RP、CAS client、业务提供方和 Webhook 接收器必须声明 `auth:none`，driver 会拒绝外部目标的 `Authorization` 头。计划必须对每一步同时声明目标、允许路径、读写性质、HTTP 状态和 JSON/响应头/响应体/Location 中至少一项业务断言；没有断言的请求不计通过。
- 每条链必须有创建、允许、拒绝、审计、撤销或恢复、撤销后实际生效，以及 API 清理后的零残留查询。管理端的成功响应断言 `code:200`；停用后的对象断言 `status:2`，不能把停用当作零残留。授权决策的“拒绝”应断言 `200 + allowed:false`，不能误写成 HTTP 403；Webhook 的 500 是 worker 投递到接收器的第一次真实结果，管理端重试接口实际返回“投递任务已重新排队”。审计列表按真实的 `action`、`resource_type`、`resource_id`、`outcome`、`request_id` 等字段筛选，分页记录在 `data.data`。
- OAuth/OIDC 与 CAS 不允许预置授权码或票据：第 5 链计划要完整记录 authorize/login、interaction/confirm、浏览器 cookie、Location 重定向、PKCE code→token，以及 CAS login、应用用户确认、ticket→serviceValidate；OAuth 的 302/JSON 和 CAS 的 XML 都是协议原始响应，不使用 SandAdmin 包装断言。完成协议前置后，同一链还必须登记应用业务动作和接口目录、创建路由绑定、完成路由清单预检与确认，并对同一策略执行模拟允许/拒绝和真实调用允许/拒绝；两者结果及审计必须一致。敏感 code/ticket/token 只保留在本轮内存变量，不会写入报告。
- plan 的 `targets` 是严格 allowlist，所有 URL 都必须是 HTTPS（仅本地受控测试可临时打开 HTTP），不支持 shell、SQL、任意地址、DELETE 或隐式写请求；持久 POST 必须显式标记 `write:true` 并受 live 的两道写入开关保护，外部纯授权 POST 才能以 `write:false` 与明确非持久 `effect` 例外登记。
- `manual_sql_cleanup` 只用于记录尚未具备受控清理能力的计划缺口，driver 从不执行它，也绝不会先向宿主写入再等待人工清理。当前七条链均已声明默认关闭的受控清理接口；第 5 链在删除前锁定 OAuth client、CAS service、API resource、route binding、policy 的精确创建审计和 OAuth/CAS/策略版本派生全集，再按 OAuth/CAS 外键及 policy version → policy 顺序物理清理。应用会话只用于实际授权决策，不可用于后台配置。该源码契约不等同于真实宿主验收。
- 第 7 链使用固定链 `event-webhook-delivery` 的专用触发接口：在本轮创建并启用的端点上，`POST /acceptance-fixture/webhook-event` 只写入一条 `acceptance.fixture.event`，其 `event_id=request_id`，并返回精确 `delivery_id`。它同样默认关闭、仅平台超级管理员可用，并核验固定 16 位前缀、端点创建审计和客户主体/应用归属；不会停用或恢复身份，也不会改动其他业务对象。worker 排空与重试完成后，清理仍严格按 **delivery → endpoint**；接收器 proof 只可作 `GET` 的 `non_persistent` 观测。仓库尚未执行任何宿主、数据库、worker 或接收器验证。
- 每条 live 链都必须同时给出 `required_fixture_gate`、`physical_cleanup_gate` 和零残留查询。`api_available:false` 的链还必须给出非空 `manual_sql_cleanup`，用于解释为什么被写入前门禁拦下；它不是可执行清理方案。`api_available:true` 也不能只靠一个布尔值或清理步骤编号放行：每个物理清理分支必须有同条件的只读零残留步骤；每个动作用 `cleanup_for` 逐项给出持久写入步骤及对应的 `capture_ids`，并在清理请求中实际提交这些对象编号。外部 POST 若只进行授权判定、没有 provider 侧持久副作用，必须写为 `write:false`、`effect:non_persistent`；若它使 SandIAM 产生调用操作，则声明 `effect:sandiam_invocation_operation` 与 `derived_request_bindings`，并由清理请求实际提交相同请求号；SandIAM 内部只写入审计的授权判定可声明 `effect:audit_only`，且不会被误当成可物理清理的夹具。创建步骤默认使用自己产生的对象编号；停用、撤销等修改既有夹具的步骤必须用 `fixture_capture_ids` 明确引用更早创建步骤产生的编号，不能引用未知编号或把普通返回字段伪装成新对象。同一链所有业务步骤新产生的捕获名称必须全局唯一，避免后续查询或写入覆盖前一步对象编号；`cleanup.steps` 不得声明任何 `capture`，只能消费业务阶段已经捕获的值。全部持久写入步骤都必须被覆盖。`physical_cleanup.evidence.chains.<链 ID>.action_contracts` 还必须逐项复述并匹配真实清理动作的请求方式、接口路径、逐步骤对象绑定、派生请求绑定和对应零残留步骤，运行环境证据必须包含本轮前缀与链 ID。零残留查询不得仅筛选 `status=1`。SandIAM 会话 Cookie 只按 `target + 当前凭证槽` 或 `target + 本轮捕获凭证` 隔离；计划不得自定义或共享会话范围，且 Cookie 绝不会转发给外部 target。

- 本地 simulator 仅是可查询 effects 的协议状态机：固定测试 credential 对允许动作返回 200，未授权动作返回 403，撤销后返回 401。它不创建或宣称任何 SandIAM credential、grant、invocation operation 或清理证据；这些结论只可由 live 链的 SandIAM API、审计和零残留查询给出。

## 真实运行前仍需的关口

示例计划不假装可以把所有夹具自动造出来。第 3 链只会注册可按本轮前缀清理的专用身份，仍需要允许注册的应用认证策略和可用的 TOTP 验证器；服务调用需要实际校验新签发凭证的受控提供方；OAuth/CAS 需要 RP、CAS client 和已登记的服务；Webhook 需要真实事件源、outbox/投递 worker 和受控 HTTPS 接收器。任一项未就绪时，该链的 live 验收应失败并保留明确缺口，不能用前端、管理 API 或接收器手工 POST 替代。

## 命令

列出七条业务链：

```bash
php sand-iam/tools/run-terminal-acceptance.php --list
```

源码预检并保存两种证据格式：

```bash
php sand-iam/tools/run-terminal-acceptance.php \
  --mode=preflight \
  --json=/tmp/sand-iam-preflight.json \
  --markdown=/tmp/sand-iam-preflight.md
```

无数据库的全链内存模拟：

```bash
php sand-iam/tools/run-terminal-acceptance.php --mode=simulated
```

只重跑一条链：

```bash
php sand-iam/tools/run-terminal-acceptance.php \
  --mode=simulated \
  --chain=event-webhook-delivery
```

真实运行只在取得本次授权后使用。先将[示例计划](../../tools/fixtures/terminal-acceptance-live-plan.example.json)复制到不纳入版本库的位置，替换所有 `__REQUIRED_*` 值；示例文件自身必定被拒绝，防止误写宿主。随后使用内置 driver：

```bash
SAND_IAM_ACCEPTANCE_LIVE=1 \
SAND_IAM_ACCEPTANCE_ALLOW_DATABASE_WRITES=1 \
SAND_IAM_ACCEPTANCE_CONFIRM=I_UNDERSTAND_THIS_WRITES_TEST_FIXTURES \
SAND_IAM_ACCEPTANCE_FIXTURE_OWNERSHIP_CONFIRM=I_OWN_THIS_PREFIXED_FIXTURE_SCOPE \
SAND_IAM_ACCEPTANCE_AUTOMATED_CLEANUP_CONFIRM=I_HAVE_VERIFIED_AUTOMATED_CLEANUP \
SAND_IAM_ACCEPTANCE_OWNERSHIP_EVIDENCE='approved sand_iam_acceptance_20260829a1b2c3d4_ fixture scope' \
SAND_IAM_ACCEPTANCE_AUTOMATED_CLEANUP_EVIDENCE='approved sand_iam_acceptance_20260829a1b2c3d4_ organization-application-environment cleanup' \
SAND_IAM_ACCEPTANCE_HOST_URL=https://approved-host.example \
SAND_IAM_ACCEPTANCE_PLATFORM_ADMIN_AUTHORIZATION='Authorization: Bearer ...' \
SAND_IAM_ACCEPTANCE_SCOPED_ADMIN_AUTHORIZATION='Authorization: Bearer ...' \
SAND_IAM_ACCEPTANCE_OUT_OF_SCOPE_ADMIN_AUTHORIZATION='Authorization: Bearer ...' \
SAND_IAM_ACCEPTANCE_APPLICATION_USER_AUTHORIZATION='Authorization: Bearer ...' \
SAND_IAM_ACCEPTANCE_SERVICE_CLIENT_AUTHORIZATION='Authorization: Bearer ...' \
SAND_IAM_ACCEPTANCE_FIXTURE_PREFIX=sand_iam_acceptance_20260829a1b2c3d4_ \
SAND_IAM_ACCEPTANCE_LIVE_PLAN=/secure/path/terminal-acceptance-live-plan.json \
php sand-iam/tools/run-terminal-acceptance.php \
  --mode=live
```

如果输出“写入前阻断”，应先补齐计划点名的受控清理 API 和零残留查询；不要用人工 SQL、先写后清或修改状态文件绕过门禁。

本地无数据库对端在 [`terminal-acceptance-local-simulator.php`](../../tools/terminal-acceptance-local-simulator.php)。`--chain3-protocol`（也被 `simulated` 第 3 链调用）经受控 fixture store 与服务语义完成注册→普通登录→MFA 启动/确认→启用 MFA 后登录仅返回一次性 challenge→动态 RFC6238 TOTP 验证签发第三会话→逐会话撤销→精确全集 cleanup；覆盖错误 identity/session/MFA/OTP、DRAIN_REQUIRED 零变更与失败审计、额外或漏项拒绝、partial failure 回滚、重试、重复 cleanup、并发 cleanup 锁和过期锁恢复。它在 cleanup 调用前记录五类关系的 DRAIN 快照、失败后再次记录并逐项比较，输出对全树递归拒绝原始前缀、请求号及认证敏感材料。`--chain7-protocol`（也被 `simulated` 第 7 链调用）从空内存状态经本地受控管理路由注册 endpoint、触发固定 `event_name=acceptance-fixture-webhook-delivery` 与 `event_type=acceptance.fixture.event`、worker 分派、接收器 500、retry、worker 分派、接收器 204、只读 proof 和 delivery→endpoint 清理；重复触发、并发 worker、过期锁、重复 retry、错误 endpoint 及未知事件名/类型均会被拒绝或安全恢复。它不调用内部 create/trigger/worker/retry/cleanup 方法，也不产生 SandIAM 持久记录。`--serve` 才会在 `127.0.0.1:${SAND_IAM_ACCEPTANCE_SIMULATOR_PORT:-18443}` 启动 HTTPS 接收器；要求 TLS 证书、私钥、每轮独立 session 与 Webhook secret，管理路由要求该 session，所有状态只在进程内存中且可清理。接收器读取完整 `Content-Length` body，5 秒读取超时、16 KiB header/body 上限。本轮不启动该服务。

第 3 链的当前协议以三条精确会话为准：注册初始会话、MFA 启用前登录会话，以及重新登录后由 `/auth/mfa/challenge/verify` 签发的会话。模拟器先断言 `mfa_required` 与 challenge，再验证动态 TOTP 后的新会话可见；三条会话与 MFA 因子都必须正常撤销，才允许 cleanup。DRAIN_REQUIRED 记录前后逐类快照并比较一致；审计上下文递归拒绝密码、access/refresh/challenge token、TOTP secret/code、恢复码、确认短语及原始验收前缀。

## 覆盖清单

| 链 ID | 闭环 |
| --- | --- |
| `organization-application-environment` | 客户主体 → 应用 → 环境 → 读取、修改、清理 |
| `identity-group-role-policy` | 身份 → 用户组 → 角色/资源/策略 → 允许、拒绝、审计、清理 |
| `human-auth-session-mfa` | 认证 → 会话 → MFA → 撤销后拒绝、清理 |
| `workload-credential-invocation` | 调用身份 → 授权 → 凭证 → 提供方副作用、拒绝、撤销、清理 |
| `oauth-cas-api-governance` | OAuth/OIDC PKCE → CAS → 应用业务动作 → 接口目录 → 路由绑定 → 清单预检/确认 → 策略模拟 → 实际调用 → 一致的允许、拒绝、审计、撤销后拒绝、清理 |
| `delegation-scope` | 平台管理员 → 委派管理员 → 范围外拒绝 → 审计、清理 |
| `event-webhook-delivery` | HTTPS Webhook → 500 重试 → 2xx、签名、审计、清理 |

报告中的 `passed/total` 是当前运行模式的明确检查数。`preflight`、`simulated` 和 `live` 分别属于静态检查、受控模拟和真实宿主证据，不能互相替代。
