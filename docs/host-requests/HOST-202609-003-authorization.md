# HOST-003：恢复检查的授权范围

## 本次任务记录

适用任务：`01a099f1-08ad-7c73-ad70-da84c7481331`，工作区
`/Users/code/project/sand_plugins`，宿主 `sandadmin-demo-host/server`。

2026-09-17 03:03:36 UTC，用户对包含“允许 SandPackage 官方 inspect
通过现有 Webman PDO 连接读取恢复状态”的 HOST-003 v3 精确恢复请求回复“批准”。
这是对该任务既有“不检查或启动 PostgreSQL”限制的具体补充，不是其他任务的通用授权。
最新用户指令优先；本文件记录范围，不签发 Hook 批准编号，不替代系统审批。

该批准包含本批恢复前后的 `php webman sandpackage:recover inspect sand-iam`
检查，以及绑定本批最终 v3 计划的 `--plan` 形式。执行器申请系统权限时应指出
这项已批准的检查及其实际读取内容，不再要求用户重复授予同一业务权限。

## 命令实际行为

已核对当前消费的 SandPackage 实现：
`Recover.php` 的 inspect 分支 → `InstallLogic::inspectFreshInstallRecovery()`
→ `FreshInstallRecovery::inspect()` / `database()`。

- 通过现有 PDO 读取数据库身份（含数据库启动时间）、候选声明表的结构、
  行数据和关联序列状态，计算恢复指纹；不能描述为“不读取数据库状态”。
- 使用安装器的互斥锁；“只读”指不修改业务数据库及不执行恢复动作，
  不保证运行时完全不写锁文件或日志。
- inspect 与执行恢复是不同分支；不能把 inspect 的批准扩展为清理、
  安装、迁移、建库或 PostgreSQL 服务启停。

若另行执行本批清理或安装，仍须匹配用户已批准的具体计划、候选及范围；
不能凭 inspect 授权执行。用户后续撤销或收窄范围时，以最新指令为准。
系统再次拒绝时，应报告原始原因及本次批准证据，不换工具绕过。

## 验证限制

本说明依据任务原始批准记录与源码分支核对；没有为验证说明再次执行数据库命令。
它修正任务的授权表述，不修改 Codex 自动审批模型，也不保证其后续不再误判。
