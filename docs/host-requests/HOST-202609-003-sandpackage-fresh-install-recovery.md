# HOST-202609-003：SandPackage PostgreSQL 全新安装失败后没有正式恢复入口

- 类型：`HOST`
- 状态：`sent / waiting host fix`
- 发送记录：2026-09-17，SandAdmin 任务 `01a0a426-e58c-7800-a253-ae143ce107b0`
- 插件及 revision：SandIAM `090992b74e79a7bf8478ee6ee2f9bc5f36cf6c4b`
- 复现范围：`单插件`；缺口位于 SandPackage 通用 PostgreSQL 生命周期状态机
- 其他复现插件及 revision（如有）：未执行
- 失败候选包 SHA-256：`075708dbfebcbac2214881a4ad979b61171fa0b55f75f4d37ca67aa2d7648817`
- 修复候选包 SHA-256：`4e0ffc8c4c8a121d0fe067c345a9b7425129c26e23f7d93ccf3e59e5cc263827`
- SandAdmin revision：demo lock `07d83d591b85deb83875473687a0d033a418c778`；本地最新 clean
  SandAdmin `b2ba538c25f0cdadb711dd89ddeecc59233c81b3` 的 `InstallLogic.php` 摘要与 demo
  相同，均为 `0d4f7bc58cbd038c26b12e01fc1d653bb62f842329d53d3a3ec17831e036034c`
- SandAdmin source state：`clean`
- demo/隔离宿主：`sandadmin-demo-host`
- 数据库/生命周期状态：既有插件已由 SandPackage 正式卸载；新装 SQL 在 040 失败后保留
  86 张 `sand_iam_*` 关系、迁移账本 40 行且最大 revision 39；插件运行目录不存在；
  registry 为 `state=8`、`operation_pending=1`

## 最小复现

1. 从正常安装的 SandIAM 0.7.2 执行 SandPackage 正式卸载。
2. 上传摘要为 `075708db...8817` 的完整包并执行正式安装。
3. 包内 040 迁移的 PostgreSQL 约束指纹故意触发确定性失败；SandPackage 返回业务 500。
4. 修复插件迁移，生成来源干净、可复现的 `4e0ffc8c...63827` 候选。
5. 调用官方只读恢复检查：

```sh
php webman sandpackage:recover inspect sand-iam
```

实际返回：`此插件使用上游 PostgreSQL 安装流程，不能调用旧恢复入口`。常规上传、安装和卸载又被
`state=8` 与 `operation_pending=1` 拒绝，因此不存在正式恢复路径。

## 预期、实际与去敏证据

- 预期：全新安装 SQL 失败后，SandPackage 能区分“未提交”“部分提交”“提交结果未知”，提供受锁、
  绑定候选身份且可审计的清理或继续入口；恢复完成后可以重新上传修复候选。
- 实际：安装器保留失败态并明确禁止直接重试，但 PostgreSQL 新流程没有对应恢复命令。旧恢复入口
  也明确拒绝该驱动。插件侧不能在不手工改 registry、删目录或直接执行 SQL 的前提下继续。
- 命令/日志/工件：
  - `server/runtime/sandpackage/sand-iam/info.ini`
  - `server/runtime/sandpackage/backups/sand-iam-uninstall-20260917094831.zip`
  - `server/runtime/logs/webman-2026-09-17.log`
  - 只读数据库证据：关系 `86`、账本 `40`、最大 revision `39`、运行插件目录不存在

## 单变量诊断

| 轮次 | 假设 | 固定项 | 唯一变量 | 请求模型/档位 | 实际模型/档位证据 | 预期观测 | 实际观测 | 新增证据/缩小范围 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | 失败来自插件 040 指纹 | 同一宿主、数据库、安装入口 | 修正 040 的类型转换/空白标准化顺序 | 主 Agent 当前配置 | 见当前任务模型记录 | 修复表达式匹配真实约束 | 真实约束标准化为 `checkpurpose='webauthn_auth'oridentity_idisnotnull`；v25 静态门 26/26 | 插件缺陷已修复，剩余阻断是失败态恢复 |
| 2 | SandPackage 已有正式 PostgreSQL 恢复入口 | 失败 registry、数据库和宿主均不变 | 调用官方只读 `recover inspect` | 主 Agent 当前配置 | 见当前任务模型记录 | 返回允许的只读诊断及下一动作 | 明确拒绝 PostgreSQL 新流程；普通动作仍被失败态锁住 | 缺口定位到宿主状态机 |
| 3 | 待 SandAdmin 维护方实现后复测 | 同一冻结失败现场 | 新宿主 revision | `gpt-6-astra/high` | 待执行 | 只读 inspect 给出受支持恢复动作 | 未执行 | 等待宿主修复版本 |

## 请求的宿主契约与验收条件

- 为 PostgreSQL 全新安装增加正式失败恢复状态，至少区分：
  `sql_not_committed`、`sql_commit_unknown`、`sql_committed_deploy_pending`。
- 每种状态绑定应用、版本、候选包、生命周期 SQL、数据库指纹、registry 和部署目录摘要；未知状态
  必须失败关闭，不能猜测重跑。
- `sql_not_committed` 可在锁内执行确定性清理后恢复为可上传；`sql_committed_deploy_pending` 只能在
  数据库和候选身份完全一致时继续部署；`sql_commit_unknown` 仅允许只读核对和明确的人工决策。
- 恢复入口必须保留其他插件的表、registry、部署文件和服务状态，并在失败、重复调用和并发调用下
  幂等。
- 用真实 PostgreSQL 验证：安装 SQL 前失败、事务内失败、提交确认丢失、SQL 成功后文件部署失败、
  服务重载失败、恢复中再次失败、重复恢复及两个并发恢复者。
- 本冻结现场须通过官方命令恢复到可重新上传状态，再以 `4e0ffc8c...63827` 候选完成
  `上传 → 安装 → 86 张关系 → 41 条账本/max 40 → 文件摘要匹配 → 服务健康`。

> 本请求不授权 SandIAM 修改 SandPackage，也不允许手工改 registry、直接执行卸载 SQL或移动运行目录。
