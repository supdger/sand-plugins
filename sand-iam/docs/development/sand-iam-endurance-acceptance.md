# SandIAM 24 小时连续稳定性验收

本门槛只接受同一个最终候选在同一环境连续运行至少 `86400` 秒的原始采样，不接受把多次短跑、
不同候选或服务重启前后的片段相加。执行前必须取得候选同步、服务启停和验收数据写入授权；
runner 本身不启动、停止、同步或修改服务。

## 适用基线

按 Webman/Workerman production baseline 记录 SLO、依赖/I-O、连接预算和 Worker 模型。24 小时
runner 直接观察规则 1、3、5、10、11、12；阻塞 I/O、连接池、背压、慢任务、SQL/cache 和
Worker 数量仍须用各自静态、负载和故障证据补齐，不能被长跑替代。

计划使用 `sand-iam.endurance-plan/v1`，必须绑定最终候选版本、ZIP SHA-256、artifact manifest
SHA-256 和干净 Git revision，并使用 `sand_iam_endurance_<16位小写十六进制>` 格式的唯一
`acceptance_run_id`。七个目标类别各且仅各一个：

- `candidate`：运行中实例返回的候选身份必须持续一致；
- `health`：宿主、插件和必要依赖健康；
- `allow`：真实受控授权路径持续允许；
- `deny`：未授权身份持续拒绝；
- `revoked`：已撤销身份或凭证持续拒绝；
- `audit`：允许、拒绝和撤销证据可按 request ID 查到；
- `metrics`：返回计划声明的十二个资源、队列、安全与 Worker 指标。

每个 probe 必须声明 HTTP 状态和至少一个 JSON Pointer 断言，禁止跟随重定向。远程地址只允许
HTTPS，且 host 必须进入计划的 `approved_hosts` 精确白名单；URL 禁止 userinfo。HTTP 只可在
计划明确开启时用于 loopback。授权值只能通过名称以
`SAND_IAM_ENDURANCE_` 开头的环境变量注入，不进入计划、响应证据或汇总。响应只记录 SHA-256、
状态、耗时、request ID 和断言结果，不保存 body；所有请求同时携带同一 acceptance run ID。
候选 probe 必须显式匹配 ZIP 与 manifest 两个摘要；allow 必须显式为真，deny/revoked 必须返回
401/403 或显式为假，audit 必须返回本轮 acceptance run ID。

## 指标口径

同一轮必须固定进程集合、队列集合、配置值和采集实现；中途改变口径即作废整轮。计数器是自进程组
启动以来的单调累计值，资源/积压是采样瞬时值：

| 指标 | 唯一验收口径 |
| --- | --- |
| `rss_bytes` | 当前宿主 Webman 主进程及本轮启用的全部 SandIAM Worker 的 RSS 字节总和；PID 重启后仍纳入同一进程组。 |
| `fd_count` | 上述固定进程组当前打开的文件描述符总数。 |
| `queue_depth` | 本轮启用集合中 Webhook `status IN (1,2)`、OIDC logout `state IN ('pending','sending')`、Sync outbox `state='pending' AND status=1`、Sync run `state='running'` 的未软删除记录总数。 |
| `unrecoverable_backlog` | 客户端、应用、主体或已撤销会话条件不再满足、因而不能走公开重新签发接口的 OIDC logout `dead`；端点已停用的 Webhook `status=4`；以及连接、应用或主体已停用而不能走公开人工重试的 Sync outbox `failed` 记录总数。可通过公开接口精确恢复的 active OIDC/Webhook/Sync 失败记录不计入，但仍须在验收证据中列出。 |
| `security_operation_retention_backlog` | 成功幂等记录超过配置保留期，再经过两个完整维护间隔仍未物理删除的行数。pending 永不计入。 |
| `auth_rate_limit_retention_backlog` | 未软删除的认证限流窗口超过配置保留期，再经过两个完整维护间隔仍未物理删除的行数。 |
| `worker_exit_total` / `worker_restart_total` | 固定进程组自本轮开始后的异常退出/重启累计数；验收前基线值可非零，但本轮增量必须为零。 |
| `unauthorized_allow_total` | 本轮 deny/revoked/跨范围探针被错误允许的累计数。 |
| `data_corruption_total` | 本轮候选探针发现账本、授权、撤销、审计或业务副作用不一致的累计数。 |
| `event_loop_lag_ms` / `pool_wait_ms` | 固定进程组本采样窗的事件循环延迟与数据库连接池等待毫秒值；最终以全轮 p99 判定。 |

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
  --summary=/controlled/endurance/samples.jsonl.summary.json
```

验证器重新检查逐行哈希链、request ID 唯一性、最终/墙钟时长、最大采样间隔、probe 集合与状态、
所有资源阈值、p99、计数器增量，以及 summary 对 JSONL 整体摘要和最终记录的绑定。

发布条件固定要求：错误率、未授权放行、数据损坏、不可恢复积压、按上述两个维护间隔口径计算的
retention backlog 以及 Worker exit/restart 增量均为零；其余 RSS、FD、斜率、队列、延迟和采样间隔
上限由等价负载下的计划冻结。
runner 通过不等于 Webman production baseline 12/12，更不等于部署或生产验证；报告必须与负载、
故障、恢复、备份和发布回滚证据一起复核。
