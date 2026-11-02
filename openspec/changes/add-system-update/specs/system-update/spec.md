## ADDED Requirements
### Requirement: Windows 与 Unix 平台系统更新
系统 SHALL 在 Windows 本地盘符宿主及 Linux/macOS 上实现相同状态、预检、后台更新、任务查询与文件恢复链路，不得因合法 Windows 绝对路径而拒绝读取任务。平台实现 SHALL 保留受信发行、互斥锁、备份摘要与外部变更保护。
#### Scenario: Windows 本地路径与固定原生命令
- **WHEN** 管理员配置合法盘符绝对路径及 PHP/Node/服务操作原生可执行 argv
- **THEN** 系统规范路径分隔符、按 Windows 大小写语义检查目录重叠，并启动不依赖宿主 vendor 与重载进程的独立 PHP worker
#### Scenario: Windows 升级命令取消或中断
- **WHEN** 命令超时、启动器退出或 PHP worker 被终止
- **THEN** Windows 作业收尾该命令全部子孙进程，任务保留现场与恢复入口，不把 PID 重用当作原任务仍活动
#### Scenario: Windows 不安全文件系统入口
- **WHEN** 配置或更新文件经过 UNC、设备路径、盘符相对路径、目录联接、符号链接、保留名称或静态目标与受保护目录重叠
- **THEN** 系统在修改文件前明确拒绝，并保留既有文件
### Requirement: 固定受信基础包更新
系统 SHALL 仅允许超级管理员选择官方 sand-core/sand-package 固定版本，并要求无 schema/骨架变更的发布契约、已安装业务插件宿主兼容范围、受管理文件无本地冲突及构建/部署/重载/健康能力预检全部通过。
#### Scenario: 缺失安全发布契约
- **WHEN** 目标没有合法发布契约或要求数据库/骨架变更
- **THEN** 系统返回明确阻塞项且不执行更新
#### Scenario: 有效计划
- **WHEN** 管理员确认通过预检的未过期计划
- **THEN** 后端冻结版本和现场指纹，创建持久任务并启动独立执行器
### Requirement: 完整更新与可观察恢复
系统 SHALL 用互斥锁执行备份、精确 Composer 安装、后端及匹配前端发布、前端构建、静态部署、配置的重载和健康检查；仅健康检查通过才成功。失败 SHALL 保留日志、摘要备份与恢复入口，拒绝恢复期间覆盖外部改动。
#### Scenario: 管理器更新自身
- **WHEN** 更新替换 vendor 或 plugin/sandpackage 并重载宿主
- **THEN** 执行器不依赖这些目录，持久任务仍持续完成
#### Scenario: 发布后健康失败
- **WHEN** 发布或健康检查失败
- **THEN** 任务明确失败并允许核对现场后恢复文件、重载和重新健康检查
#### Scenario: 恢复发生外部编辑
- **WHEN** 失败后受管理文件被其他任务修改
- **THEN** 恢复拒绝覆盖并保留 recovery_required 状态和原备份


### Requirement: 框架负责标准宿主升级准备
系统 SHALL 在普通管理员点击升级的流程内识别标准宿主、准备缺失静态基线、构建部署并验证运行服务，不要求用户手写 reload/health 配置或运行 prepare 工具。系统 SHALL 保留可信部署覆盖、受管理摘要、未知文件、进程归属及恢复保护。
#### Scenario: 标准宿主缺失可自动补足的准备项
- **WHEN** 标准宿主缺少 reload、health 或专用静态基线
- **THEN** 状态读取只读识别可准备能力；升级预检在锁内核对源码并准备基线，确认执行后才重载服务
#### Scenario: 静态入口正常但资源不可达
- **WHEN** 静态 index 可通过 HTTP 读取但 JS/CSS 路径与部署 base 不一致、缺失或 HTTP 摘要不符
- **THEN** 更新不能显示成功，任务保留原因及恢复入口
#### Scenario: 错误或变化的宿主主进程
- **WHEN** pid 文件与当前 HTTP worker 父 master 不一致，启动身份不属于此宿主或执行前身份已变化
- **THEN** 框架拒绝重载，不能向其他宿主发信号
#### Scenario: 原先没有静态页面的失败恢复
- **WHEN** 首次静态发布失败并恢复为原来的空静态基线
- **THEN** 健康检查核对原运行版本及空静态现场，不要求原先不存在的 index，也不删除其他目录
#### Scenario: Windows 已安装命令包装器
- **WHEN** 标准宿主已安装 Composer 或 pnpm/Corepack 的批处理包装入口
- **THEN** 框架解析实际 PHP/Node 脚本入口使用原生 argv，不能执行批处理或要求用户手写等价命令

#### Scenario: Windows 长原生命令参数
- **WHEN** Composer 或前端构建参数包含 Unicode、空格、引号、反斜杠，或编码后超过 Windows 启动器命令行长度限制
- **THEN** 系统通过私有临时载荷文件传递原始 argv，启动器只接收文件路径，保持参数语义和原生退出状态，并在命令结束后清理载荷
