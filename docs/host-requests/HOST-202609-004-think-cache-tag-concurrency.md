# HOST-202609-004：SandAdmin 文件缓存标签在并发首屏请求中间歇损坏

- 类型：`HOST`
- 状态：`sent / waiting host fix`
- 发送记录：2026-09-17，SandAdmin 任务 `01a0a426-e58c-7800-a253-ae143ce107b0`
- 插件及 revision：SandIAM `090992b74e79a7bf8478ee6ee2f9bc5f36cf6c4b`
- 复现范围：`中立扩展`；故障发生在 SandAdmin `CheckAuth → ReflectionCache → think-cache`
- 其他复现插件及 revision（如有）：不需要插件业务控制器即可触发
- 候选包 SHA-256：`4e0ffc8c4c8a121d0fe067c345a9b7425129c26e23f7d93ccf3e59e5cc263827`
- SandAdmin revision：demo lock `07d83d591b85deb83875473687a0d033a418c778`
- SandAdmin source state：`clean`
- demo/隔离宿主：`sandadmin-demo-host`，Webman 40 workers，默认 ThinkCache `file` 驱动
- 数据库/生命周期状态：与数据库启动无关；请求已经到达 SandAdmin 中间件

## 最小复现

1. 清除或冷启动 ReflectionCache。
2. 使用同一有效 SandAdmin JWT 并发请求 `/core/system/user`、`/core/system/dictAll`、
   `/core/system/menu`。
3. 重复 20 轮，实际 17 轮通过、3 轮至少一个端点返回 HTTP 200 / 业务码 500。

## 预期、实际与去敏证据

- 预期：三个宿主首屏接口稳定返回业务码 200，缓存标签写入在多 worker 下保持数组结构。
- 实际：间歇抛出 `InvalidArgumentException: only array cache can be push`。
- 调用栈：
  `vendor/webman/think-cache/src/Driver.php:116`
  → `TagSet->set`
  → `plugin/sandadmin/app/cache/ReflectionCache.php:90`
  → `CheckAuth.php:47`。
- 完整日志：`sandadmin-demo-host/server/runtime/logs/webman-2026-09-17.log`。
- 临时验收串行化三个宿主首屏请求后页面可继续，但这只是诊断隔离，不是产品修复。

## 单变量诊断

| 轮次 | 假设 | 固定项 | 唯一变量 | 请求模型/档位 | 实际模型/档位证据 | 预期观测 | 实际观测 | 新增证据/缩小范围 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | SandIAM 角色或菜单授权错误 | 相同 JWT、角色、菜单和插件候选 | 重复同一首屏请求 | 主 Agent 当前配置 | 见当前任务模型记录 | 固定允许或固定拒绝 | 17/20 通过、3/20 业务 500 | 排除确定性授权配置错误 |
| 2 | ThinkCache 文件标签存在并发竞态 | 相同宿主与 JWT | 将三个宿主首屏请求串行化 | 主 Agent 当前配置 | 见当前任务模型记录 | 竞态消失 | 页面进入 Passkey 业务步骤 | 定位到宿主缓存并发层 |
| 3 | 待 SandAdmin 维护方修复后压力复测 | 同一 40-worker 场景 | 新宿主 revision | `gpt-6-astra/high` | 待执行 | 反复并发无缓存结构损坏 | 未执行 | 等待宿主修复版本 |

## 请求的宿主契约与验收条件

- `ReflectionCache` 的标签集合更新必须在多 worker 下原子化，不能以读改写覆盖并把非数组中间态暴露
  给其他请求。
- 同一个标签 key 的并发首次写入、追加、过期和清理必须幂等；损坏值应安全重建并记录一次去敏告警，
  不能把宿主首屏转换成业务 500。
- 在与 demo 相同的 worker 数下，对三个首屏接口执行至少 1,000 轮并发冷/热缓存回归：
  HTTP/业务码均成功、无 `only array cache can be push`、无丢失的权限属性或跨角色污染。
- 修复应在零插件或中立控制器上证明，避免把 SandIAM 请求串行化当作宿主修复。
