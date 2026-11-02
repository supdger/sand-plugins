## Context
源码归 sand_plugins/sand-package，宿主通过 Composer 消费；基线父仓 270a4f0d2a046bbe565d911e19e207e9650dd863，两个基础包在父仓已存在但未跟踪，并非新建源码。现有 TerminalRunner 仅接受固定依赖命令，自更新会替换 vendor；FrontendPublisher 能检测部分前端冲突，但没有跨后端/前端/依赖事务、健康或恢复。本次任务用户明确要求实现整条升级链路。

## Goals / Non-Goals
升级 Sand Core 运行包及匹配前端、SandPackage 管理器。Git 骨架、用户 app/config、数据库迁移不属于此文件事务；发布契约要求这三项均不变，否则阻止升级。Linux/macOS 与 Windows 均实现原生 CLI 后台执行。2026-10-04 用户要求修复 Windows 不能更新的缺口，覆盖此前仅拒绝 Windows 的设计；源码完成、平台回归与既有宿主部署分别记录。

## Decisions
### 提供方通用性与独立审查
调用方是 SandAdmin 管理员；提供方是负责包安装/恢复的 SandPackage。core 和 package 是两个真实受益更新对象，能力不含业务插件专属字段。现有 ZIP 生命周期不能安装 Composer 基础包，现有终端不能保持自更新依赖独立；手工更新能达成但不能满足后台任务和恢复目标。执行能力提供方范围为 Package；Core 增加对应发行契约并将实际运行版本元数据同步为用户要求的 0.2.0，不修改其应用业务逻辑。
主 Agent /root（未参与实现）于本轮审查并通过通用性与边界，要求精确受管理源码、发布契约工具、实际静态发布及明确拒绝 DB/骨架变更。权限沿用仅超级管理员 adminId=1，状态是私有 runtime 文件，无新表，无身份系统。

### 受信发布与固定执行计划
仅固定官方 GitHub sand-core/sand-package 稳定 Release；每个可升级发行必须附 system-update.json。契约包含 package/version/reference、host_min/host_max、schema_changes=false、skeleton_changes=false。契约生成工具检查 Git revision 并显式声明这些边界；没有契约的历史版本显示阻塞。任务冻结契约和版本，并检查求解后的 Composer lock source reference，禁止任意包、命令、路径来自 HTTP。

### 独立执行与文件事务
纯原生 PHP 单文件执行器复制到私有 runtime/system-update/jobs/<id>/ 后启动，不加载 vendor、框架或被替换插件。API 请求立即返回任务。全局 flock 锁覆盖执行、备份和恢复；任务 JSON 原子保存，阶段日志持续落盘。持久状态可在刷新/重登后读取；服务重载时独立 CLI 保持运行。
备份仅 Composer 文件/vendor、core/package 的受管理后端、前端源码和构建产物；不触及 app/config。前端既有 manifest 与已安装 vendor 后端逐文件校验本地定制，新增未知冲突阻止。备份生成摘要；失败现场摘要用于恢复冲突保护，外部编辑不会被盲覆。恢复后重载和健康检查成功才标记 recovered，失败仍 recovery_required。

### 部署边界与可用配置
本地管理员配置固定 argv：Composer、pnpm、重载、健康检测。自动探测宿主相邻 sandadmin-artd；静态目标必须显式配置，因为宿主可能反向代理、CDN 或自行指定根目录，不能猜测覆盖。所有进程通过 argv 无 shell 执行；输出限流并剔除凭据。无需安装新 PHP 依赖。发布工具和完整常见宿主配置样例随源码交付。

### Windows 原生执行与路径边界（2026-10-04 修复）
Windows 使用系统自带 PowerShell 5.1 调用固定 Win32/.NET 启动器，JSON 数据通过私有临时载荷文件与脚本源码分离，启动器只接收载荷文件路径，避免长 argv 超出 Windows 命令行长度限制；命令结束后清理载荷。后台 PHP worker 使用 `CreateProcessW` 创建独立控制台生命周期和自身日志文件句柄，必要时显式脱离宿主 Job；宿主策略拒绝 breakaway 时明确报启动失败，不默默依附于重载进程。冻结 worker 不加载 vendor。

每个命令启动器先将自身绑定禁止 breakaway、`KILL_ON_JOB_CLOSE` 的 Windows Job Object，再启动固定 argv 原生 `.exe`，子孙进程继承 Job。启动身份包含 PID 与开始时间，先保存到任务并确认后才允许启动修改文件的命令。命令超时或 PHP worker 被终止时，Job 负责完整子孙收尾；正常命令结束也不留下其后台子孙。Windows 避免阻塞式匿名 pipe，通过私有日志文件实时轮询输出。Composer 用 PHP 加 PHAR、pnpm 用 Node 加 CLI 文件；禁止将 `.bat/.cmd` 当作直接可执行命令，也不自动引入 cmd shell。

本地盘符绝对路径接受 `/` 与 `\` 并规范为 `/`；大小写等价参与所有目录重叠比较。拒绝 UNC 网络共享、设备路径、盘符相对路径、`..`、ADS、设备保留名称、尾点/空格别名、符号链接及目录联接；树遍历、备份、删除及静态基线工具都使用相同边界。Windows ACL 沿用管理员准备的私有运行目录；Unix permission 指纹不能被解读为 Windows ACL 验证。

Windows 下状态读取可能暂时持有不共享删除的文件句柄。任务 JSON 用同一唯一临时文件对原目标执行原子替换，最多重试 2 秒、间隔 10 毫秒；不先删除目标、不修改 API 正常轮询间隔。重试耗尽保留原 JSON 并报告最后原生错误，只清理本次临时文件。真实验收同时覆盖释放 reader 后成功、reader 持续锁定时失败且旧记录不变、100 版本高频并发读，以及原后台 API 每 100 毫秒查询。

## Risks / Trade-offs
文件回滚不回滚数据库，因此拒绝 schema 变化。完整 vendor 备份占空间，预检磁盘预算。前端和服务重载短时影响连接，页面展示阶段并自动继续轮询。异常终止保留备份与恢复入口。CLI 构建通过不代替真实宿主登录/业务验收。

## Migration Plan
先发布带契约的基础包。旧 Package 0.1.9 尚无后台系统更新入口，首次接入通过正常 Composer 安装 Core 0.2.0 与 Package 0.2.1 或更高版本，保留安装钩子；已有宿主的 ^0.1 约束必须同步更新。随后管理员配置专用静态目录、固定重载/健康 argv 与实际静态基线，再由已安装的后台协调器预检更新。正常首次安装不执行后台升级、不重载、不写数据库。既有部署宿主上线另需明确具体运行影响。

## Open Questions
本轮已获公开发行、最小公共宿主交付和对应 Wiki 发布授权。真实公开消费及隔离 HTTP 运行/恢复已验收；既有部署宿主写入、共享服务重启、数据库及登录业务验收未执行，不能由隔离证据替代。


## 公开消费与入口修复的最终证据
Core0.2.0 / Package0.2.1 公开 refs、547/82 契约、正常公共 Composer 默认安装、实际 0.2 协调器到 0.2.1 的失败恢复及成功重试详见 tasks.md。状态返回按实际 installed 动态过滤远端缓存，仅展示更高版本；已安装当前发行不再成为可选更新目标。0.2.1 是真实入口缺陷修复，不改写已发布 0.2.0。宿主 PR35 只交付最低约束、正式精确锁、两 README 受管理摘要和已有 Wiki 入口；不把历史缺失但已被正式 Core 删除的资产重新长期加入宿主。


## Windows 0.2.2 发行与接入（2026-10-04）
Windows 系统更新修复已作为 SandPackage 0.2.2 正常 Composer 发行公开，tag/Release/Packagist 精确指向 `4d4d2607602b5b63b3aa06bfe7a0aa4d58af3847`。现有 Windows 的旧 0.2.1 后台仍缺少本次执行能力，不能靠本次 Release 声称旧后台自动获得支持；需按正常 Composer 更新先安装 0.2.2，再准备实际固定命令和静态基线。

公开宿主 PR41 的消费 revision 为 `642651f61322eab11cc4b223756396752356236c`，保留 Core 0.2.1，Package 更新到 0.2.2；Wiki 对应两页 revision 为 `cca71de28e548b7a5e001f1662c4abd5cc565d6a`。这是公开发行及接入说明，不表示本任务替换既有 Windows 用户宿主或访问其数据库。fresh 官方消费安装的最终证据与既有部署验收分别记录于 tasks.md；5.6 和 3.5 的既有宿主部分在实际执行前保持未完成。


公开 revision `642651f61322eab11cc4b223756396752356236c` 的 macOS / Windows fresh 默认 Composer 安装均已通过（各 102 installed / 0 updates、scripts/autoload 成功、退出 0），实际官方包 refs、受管理前后端摘要和真实 vendor 更新协调器的平台检查均通过。未配置的 reload / health / static 继续明确拒绝；这不代替真实 HTTP、完整后台更新、既有 Windows 宿主、数据库或登录业务验收。详细数量与 autocrlf 观测限制保留在 tasks.md，不由 fresh 安装自动完成 5.6/3.5。


## 自动升级准备修正（0.2.3，2026-10-05 已公开发行）
用户只需选择版本、点击升级并确认；不得把标准宿主的 reload / health / static 基线准备转嫁给普通管理员。复用本变更与原提供方边界，不增加跨插件实现。`SystemUpdateEnvironment` 从实际 `process.webman.listen`、`app.public_path`、`server.pid_file` 与 HTTP worker 祖先识别宿主；GET status 只读，inspect 在全局锁内先核对受管理源码，再准备独立静态目录及缺失基线，start 才派发实际构建、部署及重载。

默认公共目录 `admin/` 的构建使用匹配的 `VITE_BASE_URL=/admin/`，健康检查同时验证运行版本、index 和它引用的本地 JS/CSS 的真实 HTTP 内容摘要。接管已有静态内容要求与当前 dist 全树相等；有清单的目录继续拒绝未知文件与本地修改。首次空静态基线回滚只校验恢复后的原运行版本及仍为空的静态现场，不能虚构原先不存在的 index 并误判恢复失败。

Unix 将 pid 文件绑定当前 HTTP worker 的真实父 master、`ps` 启动身份及精确宿主 start_file；执行重载前再次核对身份，然后向该 master 发 SIGUSR1。Windows 核验标准 windows.php 包装器、监控目录、HTTP worker 的祖先监督器 PID/启动时间；仅在 start worker 内修改已有监控配置文件 mtime，内容不变，由原监督器处理自己的子树。默认 Composer/pnpm 从 PATH 真实安装入口解析为 PHP+PHAR 或 Node+pnpm/Corepack，保留原生命令与目录安全门槛。

运行探针仅接受 loopback、短期随机挑战，验证配置在运行进程中实际加载的版本；挑战文件在成功或失败后清除。自定义部署继续保留可信固定 argv 扩展，但标准用户不必创建脚本。未识别运行模式、缺失工具或本地冲突返回具体原因并保留现场。2026-10-05 的 0.2.3 已通过 Windows 10 / PHP 8.2.12 / PowerShell 5.1 隔离实机运行时 61 项、标准监督器自动重载与健康恢复 9 项及原生命令解析执行 2 项。Composer 为官方摘要核对后的任务临时工具，pnpm 为已有安装；该证据及正式发行不代表既有宿主已更新或登录业务通过，细节见 tasks.md。
