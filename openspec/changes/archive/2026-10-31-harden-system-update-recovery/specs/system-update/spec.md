# system-update

## Purpose

SandPackage 宿主系统更新的预检、执行、失败恢复与收尾行为：保证升级在瞬时环境失败下可安全重试、恢复不被收尾步骤假死、门禁拒绝后仍有经确认的收尾出口，且失败原因对用户可诊断。

## ADDED Requirements

### Requirement: 工具链预检验活

系统 SHALL 在开始升级前的检查中，实际执行宿主配置的 composer、pnpm、PHP CLI 命令的最小无写操作（如 `--version`）并在限时内确认其成功，不得以"命令已配置/路径存在"代替可用性验证。

#### Scenario: 工具链损坏被拦截

- **WHEN** pnpm 由失效 shim（如 corepack）提供且任何调用立即崩溃
- **THEN** 检查项 `pnpm` 标记 fail 并携带命令输出尾部，`can_start` 为 false，升级任务不创建

#### Scenario: 工具链正常

- **WHEN** 全部配置的命令在限时内返回成功
- **THEN** 验活检查标记 ok，预检继续其余项目

### Requirement: composer 阶段瞬时失败自动重试

worker 的 composer 阶段 SHALL 对依赖安装命令做有限次自动重试（利用 dist 缓存收敛），仅在重试耗尽后进入失败处理。

#### Scenario: 下载断流后重试成功

- **WHEN** composer 因网络错误（如 curl TLS EOF/超时）非零退出
- **THEN** worker 记录重试后再次执行同一命令，直到成功或达到重试上限；每次重试的日志写入任务 logs

#### Scenario: 重试耗尽

- **WHEN** 达到重试上限仍失败
- **THEN** 任务按既有失败路径处理（保留备份与 failure_fingerprint），错误消息含最后一次命令输出尾部

### Requirement: 恢复收尾与文件还原解耦

恢复的 restore（文件还原）完成后，其后的收尾步骤（前端依赖核对、reload、health）失败 SHALL NOT 把任务标为文件未恢复；任务应记为 recovered（或同等完成态）并保留收尾警告。

#### Scenario: 文件已还原但 pnpm 收尾失败

- **WHEN** restore 阶段完成且所有范围与备份一致，随后 `pnpm install` 失败
- **THEN** worker 继续执行 reload 与健康检查，任务标记已恢复，错误字段记录"前端依赖核对失败，请手工执行 pnpm install"类警告，任务不再停留 recovery_required

#### Scenario: 文件还原本身失败

- **WHEN** restore 阶段校验或拷贝失败
- **THEN** 任务进入失败态且不声称已恢复，保留现场供人工核对（既有行为不变）

### Requirement: 指纹不匹配的人工收尾出口

当恢复因 `failure_fingerprint` 与当前文件不一致被拒绝时，系统 SHALL 提供经显式确认的收尾操作：跳过文件覆盖，仅执行收尾（前端依赖核对、reload、health）并将任务置为完成态；该操作 MUST 要求精确确认且不得修改被保护范围外的文件。指纹门禁本身（禁止静默覆盖外部改动）MUST 保持强制。

#### Scenario: 外部改动后经确认完成收尾

- **WHEN** 任务因指纹不匹配被拒绝，操作者提交精确确认文本请求人工收尾
- **THEN** 系统跳过文件还原，执行收尾步骤并关闭任务为完成态，日志记录该确认行为

#### Scenario: 未经确认的收尾请求

- **WHEN** 操作者请求人工收尾但未提供要求的确认文本
- **THEN** 请求被拒绝且任务状态不变

### Requirement: 失败原因可诊断

预检与任务检查项的失败消息 SHALL 包含失败命令输出的尾部内容（经脱敏、限长），不得仅有"退出码 N"。

#### Scenario: solver 预检失败

- **WHEN** 预检的 composer dry-run 因依赖冲突退出非零
- **THEN** 对应检查项消息包含 solver 输出尾部（如"package not found"/冲突详情），用户可在界面看到原因

### Requirement: 完成与提示

升级或恢复完成后，任务消息 SHALL 在使用前端开发模式的部署上提示重启前端 dev server（vite 需重扫组件），生产静态部署由既有 manifest 健康检查覆盖、不额外要求。

#### Scenario: 恢复完成提示

- **WHEN** 恢复以警告态（pnpm 收尾失败）或成功态完成
- **THEN** 任务日志/消息包含 dev 前端重启提示与手工核对指引
