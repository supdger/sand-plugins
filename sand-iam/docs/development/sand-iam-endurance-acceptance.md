# SandIAM 8 小时连续稳定性验收

本门槛只接受同一个最终候选在同一环境连续运行至少 `28800` 秒的原始采样，不接受把多次短跑、
不同候选或服务重启前后的片段相加。执行前必须取得候选同步、服务启停和验收数据写入授权；
runner 本身不启动、停止、同步或修改服务。

## 适用基线

### Contract checkpoint（2026-09-12）

按 `write-gate begin --replace` 归档既有 contract：endurance v1 审计为 **4P1 + 3P2**；v2 分三批修复，最终经 Astra 工具复核 **ACCEPT（P0/P1/P2=0）**。该结果只覆盖离线 contract/tool 结构，不代表 ready、真实 HTTP 或真实长跑。协议是协作式可信环境边界：JSONL 哈希链仅提供完整性和篡改可见性，不是签名、身份认证或防伪；Git 未独立重建，collector/probe 真实性依赖受控环境、独立保管和可信对端。所有 fixture 均不是 28800 秒，真实同一最终候选 8 小时运行尚未开始（**0**）。external validators 的 fixture 修复即使离线结构 ACCEPT，也不代表 ready。

按 Webman/Workerman production baseline 记录 SLO、依赖/I-O、连接预算和 Worker 模型。8 小时
runner 直接观察规则 1、3、5、10、11、12；阻塞 I/O、连接池、背压、慢任务、SQL/cache 和
Worker 数量仍须用各自静态、负载和故障证据补齐，不能被长跑替代。

计划固定使用 `sand-iam.endurance-plan/v2`；v1 计划、JSONL 或 summary 一律不能作为 release
通过证据。v2 必须绑定最终候选版本、ZIP SHA-256 与字节数、artifact manifest SHA-256、干净 Git
commit/tree、环境 ID、采集器 ID/version，以及 `sand_iam_endurance_<16位小写十六进制>` 格式的唯一
`acceptance_run_id`。计划原始字节 SHA-256、阈值摘要和 endpoint 摘要会连同这些身份写入首条及每条
JSONL 记录和 summary；任一候选、run、计划、阈值或 endpoint 重绑定均作废。七个目标类别各且仅各一个：

- `candidate`：运行中实例返回的候选身份必须持续一致；
- `health`：宿主、插件和必要依赖健康；
- `allow`：真实受控授权路径持续允许；
- `deny`：未授权身份持续拒绝；
- `revoked`：已撤销身份或凭证持续拒绝；
- `audit`：必须在本轮 allow、deny、revoked 三个业务响应完成后查询；请求携带且响应逐项回显三条上轮业务 `request_id`、decision、subject、scope、candidate 与 acceptance run，并给出业务侧和 SandIAM 侧 audit 引用及业务副作用/拒绝/撤销观察；
- `metrics`：返回计划声明的十三个资源、队列、安全与 Worker 指标，以及稳定的启动与进程组身份。

每个 probe 必须声明 HTTP 状态和至少一个 JSON Pointer 断言，禁止跟随重定向。远程地址只允许
HTTPS，且 host 必须进入计划的 `approved_hosts` 精确白名单；URL 禁止 userinfo。HTTP 只可在
计划明确开启时用于 loopback。授权值只能通过名称以
`SAND_IAM_ENDURANCE_` 开头的环境变量注入，不进入计划、响应证据或汇总。响应只记录 SHA-256、
状态、耗时、request ID 和逐 JSON Pointer 断言结果，不保存 body；所有请求同时携带同一 acceptance run ID。
每个断言固定只含 `pointer`、`expected_kind`、`expected_sha256`、`actual_kind`、`actual`、`actual_sha256`
和 `result`；缺任一字段、出现未知字段或类型不符均失败。expected 不写原值，只写由绑定计划复算的 kind 与
canonical SHA-256（null 同样使用 kind=`null` 与其 canonical hash）；actual 始终记录 kind/hash，只有非敏感
pointer 的 null、布尔或数值可写入 `actual`。字符串和复合值只写 hash；pointer 包含 password、client_secret、
secret、token、otp 或 code 时，不论实际类型一律不写 `actual`。非 object/array 的已解码响应（包括 null 和标量）、
缺少 pointer 或不等候选均失败，不能只凭 HTTP 状态通过；错误输出也不得包含断言值。
候选 probe 必须显式匹配 ZIP 与 manifest 两个摘要；allow 必须显式为真，deny/revoked 必须返回
401/403 或显式为假，audit 必须返回本轮 acceptance run ID。计划中的 allow、deny、revoked 目标还必须各有
`audit_context`（冻结 decision、subject、scope、candidate、业务副作用和业务 audit 引用 JSON pointer）；audit
目标必须有 `audit_response`，明确 records 列表及九个关联字段的 JSON pointer。runner 只在三条业务响应后发起
audit POST，并将该轮刚生成的 request IDs 与上述上下文发送给 endpoint；任何缺失、重复、旧缓存、静态 run ID、
跨轮、candidate/decision/subject/scope 不一致或无业务副作用和双侧 audit 引用均失败。原始 JSONL 保存这些已白名单化
的关联字段；不保存响应 body、授权头或其他敏感原文。

## 证据 I/O 与独立读取

每条 JSONL 通过循环完成全部字节写入；短写会续写，0 字节、写入/flush/fsync 失败（平台支持 fsync 时）立即失败，
绝不把半条记录记为成功。transport error 仅记录白名单 `category`/稳定 `code`（如 `timeout`/`curl_28`），
不记录 `curl_error()` 原文。summary 拒绝既有目标、符号链接和非 regular 目标；它使用同目录、随机且 `O_EXCL` 创建的
临时 regular 文件，完整写入、flush/fsync 后以同文件系统 `link(temp, final)` 无覆盖发布，成功后只 unlink 已验证 dev/inode
仍归本次所有的 temp，绝不以 rename 覆盖 final；平台可打开目录时继续 fsync 目录。任一失败只清理已确认归属的临时文件。

证据稳定性采用协作式不可变协议，而不是把 stat 当作对抗性防护：runner 从 JSONL `O_EXCL` 创建起保持
`LOCK_EX`，每次 append 都在该锁内完成 flush/fsync，且 JSONL 始终保留 write bit。先将 summary 临时文件
flush/fsync、设为 `0444`，以无覆盖 link 发布、unlink 已验证归属的 temp，并成功 fsync 目录；只有这一切成功后，
才把仍持锁的 JSONL 设为 `0444` 并 fsync 其 inode。JSONL 最终 fsync 成功后不再执行可能改变完成资格的操作。
在此之前任一步失败都保留或恢复 JSONL 的 write bit，并尽力只删除已验证归本次所有的 summary 后再同步目录；最终 JSONL
fsync 失败时同样先恢复可写并 fsync，恢复失败才删除已验证归属的 summary。因此常规可观察失败不会留下可被 verifier
当作完成的只读 evidence+summary 对；文件系统同时拒绝 chmod、fsync 和 unlink 等灾难性故障无法保证可恢复，不能据此宣称
对抗性原子性。verifier 对 JSONL/summary 只接受 regular、非 symlink、无 write bit 的
完成证据，且必须立即取得 `LOCK_SH|LOCK_NB`；plan 也固定 FD 并取得同一共享锁，但不要求由 runner 封存。否则拒绝；然后完整读取并独立 hash/parse。该协议为
遵循锁和只读约定的并发读者提供稳定 snapshot，拒绝运行中的 writer、可写“完成”文件和尾截断；它不防御 owner 或同权限恶意方
重新 chmod/改写。完整性链不是外部防伪，需可信存储与签名/独立保管来提供该保证。

## 指标口径

同一轮必须固定进程集合、队列集合、配置值和采集实现；中途改变口径即作废整轮。计数器是自进程组
启动以来的单调累计值，资源/积压是采样瞬时值：

| 指标 | 唯一验收口径 |
| --- | --- |
| `rss_bytes` | 当前宿主 Webman 主进程及本轮启用的全部 SandIAM Worker 的 RSS 字节总和；PID 重启后仍纳入同一进程组。 |
| `fd_count` | 上述固定进程组当前打开的文件描述符总数。 |
| `queue_depth` | 本轮启用集合中 Webhook `status IN (1,2)`、OIDC logout `state IN ('pending','sending')`、Sync outbox `state='pending' AND status=1`、Sync run `state='running'` 的未软删除记录总数。 |
| `unrecoverable_backlog` / `unrecoverable_backlog_total` | 前者是客户端、应用、主体或已撤销会话条件不再满足、因而不能走公开重新签发接口的 OIDC logout `dead`、已停用 Webhook `status=4` 和不可人工重试的 Sync outbox `failed` 的瞬时总数；后者是同口径本轮累计计数。前者逐样本必须为零；后者基线可非零但不得变化。可通过公开接口精确恢复的 active 失败记录不计入，但仍须在证据中列出。 |
| `security_operation_retention_backlog` | 成功幂等记录超过配置保留期，再经过两个完整维护间隔仍未物理删除的行数。pending 永不计入。 |
| `auth_rate_limit_retention_backlog` | 未软删除的认证限流窗口超过配置保留期，再经过两个完整维护间隔仍未物理删除的行数。 |
| `worker_exit_total` / `worker_restart_total` | 固定进程组自本轮开始后的异常退出/重启累计数；验收前基线值可非零，但本轮增量必须为零。 |
| `unauthorized_allow_total` | 本轮 deny/revoked/跨范围探针被错误允许的累计数。 |
| `data_corruption_total` | 本轮候选探针发现账本、授权、撤销、审计或业务副作用不一致的累计数。 |
| `event_loop_lag_ms` / `pool_wait_ms` | 固定进程组本采样窗的事件循环延迟与数据库连接池等待毫秒值；最终以全轮 p99 判定。 |

计划的 `interval_seconds` 只能为 10–300；`max_jitter_seconds` 是 0–60 的冻结整数，且
`max_gap_seconds` 必须精确等于两者之和，不能另行放宽。`max_clock_skew_seconds` 为 1–5 秒的冻结
UTC/单调时间差容差。每条样本携带同一个严格 UTC RFC3339 `run_started_at`、严格 UTC RFC3339
`wall_time` 和单调整数 `elapsed_microseconds`：首样本必须在 max gap 内，所有间隔都不得超过 max gap，末样本
必须覆盖 28800 秒且不得越过尾部一个 max gap。墙钟每个 delta 与单调 elapsed delta 的差不得超过冻结
容差；秒级 UTC 相邻样本可相等，但不得倒退，并以累计 wall/monotonic 差校验。慢钟达到冻结容差即失败，
不以继续采样掩盖；倒退、前跳、非法日期、PHP 日期归一化、NaN、Inf、负数和零/重复 elapsed 均失败。实际运行从共同
start 基线计满 28800 秒，不因首个 probe 已消耗的时间而把真实满时长拒绝。

metrics 响应还必须按计划 JSON pointer 给出 `boot_id`、`process_group_id` 和
`supervisor_restart_total`。三者在首样本建立基线，后续每条完全相同；缺失、格式非法或变化即失败，
不能把进程内 counter 当作跨重启可靠证据。所有 metrics 都逐样本为有限非负；`worker_exit_total`、
`worker_restart_total`、`unauthorized_allow_total`、`data_corruption_total`、`unrecoverable_backlog_total`
以首样本为基线且必须单调不变，任何正增量、下降、归零或 reset 均失败。上述累计值和
`supervisor_restart_total` 只接受非负平台整数，或超出平台范围的无前导零十进制字符串；按长度和字典序精确比较，禁止浮点。三个安全积压 gauge
`unrecoverable_backlog`、`security_operation_retention_backlog`、`auth_rate_limit_retention_backlog` 每条均为零。

两个 retention backlog 必须用 PostgreSQL `CURRENT_TIMESTAMP` 与本轮真实配置计算，不能用采集器本机
时钟、总表行数或“本次删了多少”代替。下列参数均为已通过配置预检的整数：

```sql
SELECT count(*) AS security_operation_retention_backlog
FROM sand_iam_security_operation
WHERE state = 'succeeded'
  AND update_time <= CURRENT_TIMESTAMP
      - make_interval(days => :retention_days, secs => :interval_seconds * 2);

SELECT count(*) AS auth_rate_limit_retention_backlog
FROM sand_iam_auth_rate_limit
WHERE delete_time IS NULL
  AND window_start <= CURRENT_TIMESTAMP
      - make_interval(hours => :retention_hours, secs => :interval_seconds * 2);
```

当前候选的队列 SQL 口径如下。采集器可拆成多条只读查询再求和，但不得新增、删减状态，也不得把查询失败回填为零；所有表都只统计 `delete_time IS NULL`：

```sql
SELECT
    (SELECT count(*) FROM sand_iam_webhook_delivery
      WHERE delete_time IS NULL AND status IN (1, 2))
  + (SELECT count(*) FROM sand_iam_oidc_logout_delivery
      WHERE delete_time IS NULL AND state IN ('pending', 'sending'))
  + (SELECT count(*) FROM sand_iam_sync_outbox
      WHERE delete_time IS NULL AND state = 'pending' AND status = 1)
  + (SELECT count(*) FROM sand_iam_sync_run
      WHERE delete_time IS NULL AND state = 'running') AS queue_depth;

SELECT
    (SELECT count(*)
       FROM sand_iam_oidc_logout_delivery delivery
       JOIN sand_iam_oauth_client client ON client.id = delivery.oauth_client_id
       JOIN sand_iam_auth_session session ON session.id = delivery.auth_session_id
       JOIN sand_iam_application application ON application.id = delivery.application_id
       JOIN sand_iam_organization organization ON organization.id = application.organization_id
      WHERE delivery.delete_time IS NULL AND delivery.state = 'dead'
        AND (client.delete_time IS NOT NULL OR client.status <> 1
          OR client.backchannel_logout_uri IS NULL OR client.backchannel_logout_uri = ''
          OR session.delete_time IS NOT NULL OR session.status <> 2 OR session.revoked_time IS NULL
          OR application.delete_time IS NOT NULL OR application.status <> 1
          OR organization.delete_time IS NOT NULL OR organization.status <> 1))
  + (SELECT count(*)
       FROM sand_iam_webhook_delivery delivery
       JOIN sand_iam_webhook_endpoint endpoint ON endpoint.id = delivery.webhook_endpoint_id
      WHERE delivery.delete_time IS NULL AND delivery.status = 4
        AND (endpoint.delete_time IS NOT NULL OR endpoint.status <> 1))
  + (SELECT count(*)
       FROM sand_iam_sync_outbox outbox
       JOIN sand_iam_sync_connector connector ON connector.id = outbox.sync_connector_id
       JOIN sand_iam_application application ON application.id = outbox.application_id
       JOIN sand_iam_organization organization ON organization.id = application.organization_id
      WHERE outbox.delete_time IS NULL AND outbox.state = 'failed'
        AND (connector.delete_time IS NOT NULL OR connector.status <> 1
          OR connector.direction NOT IN ('outbound', 'bidirectional')
          OR application.delete_time IS NOT NULL OR application.status <> 1
          OR organization.delete_time IS NOT NULL OR organization.status <> 1)) AS unrecoverable_backlog;
```

两个额外维护间隔只用于区分正常调度边界和持续积压，不延长实际删除截止时间；Worker 仍在记录到达
保留期后的首个可用 tick 尝试删除。任一采样查询失败、超时或返回负数都算该次 metrics probe 失败，
不得回填为零。

最终 unsigned release candidate 生成后，先从其包外 manifest 生成候选绑定模板：

```bash
php sand-iam/tools/prepare-external-acceptance.php \
  --kind=endurance \
  --artifact-manifest=/controlled/candidate/manifest.json \
  --output=/controlled/endurance/plan.json
```

模板故意包含非法 `__REQUIRED_*` host 和负数阈值，未逐项替换前必定关闭失败。不要把某次历史
候选的计划复制给新候选。补齐等价负载下冻结的阈值和七个真实 endpoint 后，先只读校验计划：

```bash
php sand-iam/tools/run-endurance-acceptance.php \
  --plan=/controlled/endurance/plan.json \
  --validate-only
```

取得本次运行授权、确认候选已同步并完成基线冒烟后执行：

```bash
SAND_IAM_ENDURANCE_ALLOW_WRITES=1 \
SAND_IAM_ENDURANCE_ADMIN='Bearer ...' \
SAND_IAM_ENDURANCE_USER='Bearer ...' \
php sand-iam/tools/run-endurance-acceptance.php \
  --plan=/controlled/endurance/plan.json \
  --output=/controlled/endurance/samples.jsonl
```

只有计划全为 `read_only` 时才可省略写入确认。`audit_only` 和 `idempotent_fixture` 会产生受控
验收记录，必须纳入前缀限定的清理清单。输出 JSONL 使用逐记录 SHA-256 链，summary 绑定整个
JSONL 摘要、最终记录摘要、候选身份、p50/p95/p99、采样间隔、RSS/FD 最大值及线性斜率、队列、
幂等操作保留积压、过期认证限流窗口积压、Worker exit/restart、安全计数和 event-loop/pool wait p99。

完成后必须由另一进程重新计算原始证据，而不是只相信 summary 的 `passed`：

```bash
php sand-iam/tools/verify-endurance-evidence.php \
  --plan=/controlled/endurance/plan.json \
  --evidence=/controlled/endurance/samples.jsonl \
  --summary=/controlled/endurance/samples.jsonl.summary.json \
  --archive=/controlled/candidate/sand-iam.zip \
  --artifact-manifest=/controlled/candidate/manifest.json
```

验证器固定读取包外 ZIP 和 manifest，重新核验 ZIP SHA-256/字节数、manifest SHA-256、manifest 的
candidate/source provenance、ZIP 每个文件 hash/size 与 entry count；再从原始安全断言结果和值、逐行
哈希链、request ID 唯一性、共同 start 的严格 UTC/单调时间连续性、首尾覆盖、最大采样间隔、稳定运行身份、
逐样本安全计数器、probe 集合与状态、所有资源阈值、p99 和计数器增量独立复算。它不以 summary 的 `passed` 或摘要数值替代这些检查；summary 只作为必须与复算
结果一致的冗余绑定。

JSONL 哈希链仅提供完整性和篡改可见性，不是签名、身份认证或防伪机制。metrics、时间口径和真实 8 小时运行
仍须实际验收；本地 fixture 对 I/O 与 audit 只验证 fail-closed 契约，不构成真实 8 小时或真实 HTTP 通过。

发布条件固定要求：错误率、未授权放行、数据损坏、不可恢复积压、按上述两个维护间隔口径计算的
retention backlog 以及 Worker exit/restart 增量均为零；其余 RSS、FD、斜率、队列、延迟和采样间隔
上限由等价负载下的计划冻结。
runner 通过不等于 Webman production baseline 12/12，更不等于部署或生产验证；报告必须与负载、
故障、恢复、备份和发布回滚证据一起复核。
