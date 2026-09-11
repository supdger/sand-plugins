# SandIAM 目录同步 Worker 运维说明

目录同步 worker 默认不启动。它只从已启用、已配置且所属组织与应用同样启用的同步连接领取工作；配置密文、游标和远程响应不会写入 worker 日志。

## 五个 worker 配置

| 环境变量 | 默认值 | 作用 |
| --- | --- | --- |
| `SAND_IAM_DIRECTORY_SYNC_WORKER_ENABLED` | `0` | 唯一启动开关；非 `1` 时 `process.php` 不注册 worker。 |
| `SAND_IAM_DIRECTORY_SYNC_WORKER_INTERVAL_SECONDS` | `60` | 两次调度 tick 的最小间隔，最小为 1 秒。 |
| `SAND_IAM_DIRECTORY_SYNC_WORKER_BATCH_SIZE` | `20` | 单个 tick 最多领取的连接数，限制为 1–100；每个组织最多一个。 |
| `SAND_IAM_DIRECTORY_SYNC_WORKER_RETRY_BASE_SECONDS` | `5` | 可重试远程/驱动失败的首次退避秒数。 |
| `SAND_IAM_DIRECTORY_SYNC_WORKER_RETRY_MAX_SECONDS` | `300` | 指数退避的上限秒数。 |

启动前还必须显式设置既有的 `SAND_IAM_IDENTITY_LIFECYCLE_ENABLED=1`，并完成适用 lifecycle、连接配置和运行验收。仅打开 worker 开关不会绕过生命周期开关。

## 运行与恢复边界

- worker 单进程运行；它保存本进程生命周期内的 connector-id keyset 位置。每 tick 只作一次有界 `id > after_id` 查询，到尾时才作一次有界 wrap 查询；位置跟随实际领取的连接而非固定低 ID 前缀。组织和同组织连接均在这个 keyset 上轮转，避免窗口外或低 ID 连接长期饥饿。
- `SyncConnectorService` 仍是游标、冲突、停用传播、运行记录和审计的唯一执行者。页只有在成功提交后才推进游标；失败后从保留游标重试。
- 仅远程限流/远程失败、驱动响应异常和未分类运行失败进入进程内指数退避。配置、冲突或熔断等可修正业务错误不进入指数退避，仍会在后续 tick 被再次领取并由服务记录失败；操作员修正后无需等待额外 backoff，也可从管理端显式运行恢复。
- 停止信号不再领取新连接；已经开始的同步会在当前同步调用返回后结束。worker 不尝试取消远程 I/O，以免产生未知的半完成副作用。
- 重试状态只存在进程内。连接被停用、删除或变为未配置/不再 eligible 时会在下一 tick 清理；进程重启后由当前连接状态重新决定是否领取。

本说明不代表真实目录、宿主 HTTP、并发或发布验收已经通过。
