## 1. Source documentation

- [x] 1.1 核对五单元入口、版本元数据、标签与发行说明；以证据限定日志范围。
- [x] 1.2 补齐 Core、AI、Workflow 日志并链接五单元 README 与根 README；检查入口目标存在与候选标记。

## 2. Version notes delivery

- [x] 2.1 扩展 document 类型与版本详情日志入口；documents-only 回归验证默认 README、当前日志、旧包缺失、非法类型和摘要绑定；真实页面交互尚未运行。
- [x] 2.2 构建包携带日志、自动生成对应版本 notes 与显式覆盖；RepositoryPackageBuildTest 验证章节边界、缺失、提取、覆盖与日志打包。

## 3. Acceptance

- [x] 3.1 完成源码与文档契约的独立审查、日志入口核对及 OpenSpec strict 校验；独立源码/包行为验收通过，证据与限制如下。
- [ ] `[device-gate]` 3.2 在真实目标宿主版本详情页验收日志入口、README/日志切换及旧包缺失提示；当前未运行，不以源码编译或包测试替代。

## Evidence

- Core：已缓存权威仓 origin 为 https://github.com/supdger/sand-core.git；0.1.9 标签剥离提交 b2353810d8c634c9c47f2636f8719f68ea17c02f，0.1.8 为 43b547a5651ec8912ad28792a577c5ea7f05de38；CHANGELOG 逐条复用 0.1.9 标签已有记录。
- AI：根仓 sand-ai-v0.1.0-preview-216cccf 的 README / info.ini 确认旧插件布局与 0.1.0 基线；当前 README、0.1.3 元数据、迁移 101 / 102 确认两候选变化；其余统一包变化置 Unreleased，不补造已发布版本。
- Workflow：根仓 sandworkflow-v1.0.7-preview-216cccf 与标签内 RELEASE_NOTES；7282a244 确认可发现性修复，61fd9b1d 确认许可证补齐；当前本地宿主支持声明与 Wiki 入口置 Unreleased。
- 本轮 gh api 对三公开仓均返回 error connecting to api.github.com；未声称重新核实线上 Release 或发布日期。

- 文档入口：五单元 README 与根 README 共 10 个更新日志引用和目标文件均检查通过；相关 tracked 文档 diff --check 通过。Core/AI/Workflow 日志由独立文档审查复核，依据独立审查补齐会议可续跑模式的迁移 109/111/112、默认禁用与正式升级/验收后启用边界，未分配已发布版本。
- 代码执行者验证：TMPDIR=/private/tmp SANDPACKAGE_TEST_VENDOR=/Users/code/project/sand_ai/server/vendor/autoload.php php sand-package/server/tests/SandPackage/RepositoryDistributionTest.php --documents-only 退出 0；相同环境运行 RepositoryPackageBuildTest.php 退出 0。PHP 三个修改文件 lint 与 Vue SFC script/template 编译通过。此为执行者回报，未冒充文档作者重跑。
- 限制：全 RepositoryDistributionTest 后段 install(false) 宿主兼容性检查失败，未完成根因归属，不宣称全套通过。真实版本详情页面的打开、日志/README切换及旧包提示尚未运行；无宿主同步、提交、发布或线上消费验证。

- 新构建参数说明：核对 build-plugin-package.php 参数位置、UTF-8/256 KiB 日志和 8000 字节摘要限制；Package README 同步自动提取、显式覆盖（含空值）和缺匹配留空。当前权威源码单元无本地 Wiki 源码，未建立第二套教程或推送 Wiki。

- 最终独立文档复验：solhigh__changelog_docs_review 确认 AI 续跑升级条件及 102 边界、Package README 参数与实际构建器一致，重新 strict validate 通过。
- 独立源码/包行为验收者 Astra 回报：documents-only 与完整 RepositoryPackageBuildTest 独立执行均退出 0；256 KiB 日志接收、超 1 字节拒绝、无效 UTF-8 拒绝，以及 5 个章节边界实例通过。此为独立验收者实测回报，不是文档作者重跑。
- 最终限制：完整 RepositoryDistributionTest 安装兼容性失败尚未归属；相关源码为既有 untracked 内容且无改前完整副本，不能宣称失败早于本次修改。真实 UI、部署、公开发行、宿主消费均未验证；未执行提交、推送、发布或归档。


## 4. Discoverable README and Wiki updates（用户纠正，2026-10-03）

- [x] 4.1 将五单元 README 改为靠前的独立“版本更新”栏目，提供中文 Wiki、完整历史与持续发行入口，版本正文和升级影响维护在 Wiki；根 README 聚合五项 Wiki 入口。
- [x] 4.2 为 Core、Package、AI 建立真实公开仓的隔离文档候选，保留公开源码，不混入本仓 dirty 产品实现。沿用真实 SandAdmin Wiki，在 Home、_Sidebar、宿主 changelog 与 plugin-updates 页连通读者路径。
- [x] 4.3 对各现有 Wiki 执行本地链接检查，并从仅提供公开候选文档的冷读者上下文验证“寻找更新→正文变化→升级影响→完整历史”；独立冷读者验收通过，结果见下方。
- [x] 4.4 本轮文档提交、推送/合并与 Wiki 发布取得明确授权后执行，并核对公开入口；六源码仓文档与三个既有 Wiki 已公开交付，最终结果见下方。

本轮基础组件与 AI 文档证据：

- 隔离候选根 `/private/tmp/sand-update-navigation-20261003-foundation`。Core 公开 main `b2353810d8c634c9c47f2636f8719f68ea17c02f`；Package main `da30ce7c62245047c0ea5733432cffa670952fe3`；AI main `19f6f8e949576b8024fe01045b70d8ec8235dafa`；SandAdmin Wiki `62ea06eab8dd70ea1ab47d2baad647d98e46c94a`。均从公开 remote 本轮读取的 clean baseline，只套文档变化。
- gh API 实时核实 Core 0.1.9（2026-10-02 18:46:08Z）、Package 0.1.10-rc.1（2026-10-01 08:28:40Z，稳定仍0.1.9）、AI `sand-ai-v0.1.0-preview-2821503`（2026-09-21 06:57:21Z）。AI 未发布统一包候选不写成公开成品，不改产品依赖。
- Core/Package/AI 独立 Wiki 的只读 ls-remote 均返回 Repository not found；不创建新 Wiki，沿用现有 SandAdmin Wiki `plugin-updates`。IAM/Workflow 页 slug 为 `Changelog`，其候选由另一执行者准备。
- SandAdmin Wiki `check_wiki.py`：19 页、82 本地目标，0 错误、0 警告；43 外链未由该检查器核实，不冒称渲染/公开链接验收。公开仓候选 diff --check 均通过。最短路径：README“版本更新”→Wiki `plugin-updates`，或Wiki Home/Sidebar“版本更新”→`plugin-updates`→对应组件正文及完整历史。
- 发布候选范围：Core README；Package README；AI README + 新增公开预览 CHANGELOG；SandAdmin Wiki Home、_Sidebar、changelog + plugin-updates。Core/Package 现有公开日志保留，不以本仓候选覆盖。无数据库、宿主同步、启停或产品 Release。

- 独立冷读者 `solhigh__update_navigation_reader` 经主控汇总最终通过：5组件发现路径5/5，公开根README聚合路径5/5；6个Release API记录与文案一致。Workflow4处链接与IAM指代修正后复验通过，Core上海时区日期正确，无剩余必修项。此为候选静态读者验收，不是已发布页面或真人操作验收。
- 公开根聚合仓 baseline `da31a4749f3b84db08e147455a745bc2216685d8`，候选 `/private/tmp/sand-update-navigation-20261003-foundation/sand-plugins/README.md` 仅增加本轮版本更新聚合表，保留公开迁移说明。Wiki首页无行为措辞清理后仍保持相同导航。公开文档提交、推送/合并、三个Wiki发布及线上入口复验均 pending，未执行。


## 5. README 规范返修与授权公开交付（2026-10-03）

- [x] 5.1 实际读取 Webman AOT README，对 Core/Package/AI/聚合公开候选修正为使用者介绍、源码/安装边界、真实最小用法及结果；“版本更新”保留中文持续入口，固定版本正文移到 Wiki。
- [x] 5.2 新候选独立复验通过后提交、推送及合并四基础/AI/聚合源码仓；本线公开结果见下方。IAM/Workflow 两源码仓由对应执行者交付，其状态由主控汇总。
- [x] 5.3 发布三个既有 Wiki 的本批差异，并重新读取公开默认分支及入口；六仓文档默认分支与三 Wiki 的公开读取核实完成，未替代宿主业务验收。

本线新增参考页 `package-reference.md` 承接公开 Package README 的宿主应用载荷、schema 重接与兼容说明；`sandai-plugin-maintenance.md` 承接公开 AI README 的源码同步与维护验证长篇。只在真实既有 SandAdmin Wiki 维护，Home/_Sidebar 连通。Package 固定 rc 安装步骤移到既有 `plugin-updates.md`。Core 公开 README 本来没有本仓已有数据库接入候选，因此不复制该未发布候选进 Wiki。

修订候选静态验证：SandAdmin Wiki 21页、91本地目标、0错误0警告（45外链检查器不验证）；四源码仓与Wiki diff检查通过。新候选独立验收和公开发布待主控调度。


### Foundation / AI / aggregate 本线公开交付结果（2026-10-03，上海）

独立审查 `solhigh__readme_standard_acceptance` 明确通过本线四个完整 README 与 SandAdmin Wiki 本批六页，无必须修复项。该审查是修订后整页规范审查，未以此前仅查导航的结果替代。

- Core：[PR #9](https://github.com/supdger/sand-core/pull/9)，固定文档 head `6adbef25469e469c5d75270c84a9302cd16b86d9`；已合并公开 main `13b2c8658b4543ebe968a793b49c044adb323180`，仅 README。
- Package：[PR #13](https://github.com/supdger/sand-package/pull/13)，head `2f9ba3955e581f60ec200227dc51fa36059b5d09`；已合并 main `481fc92eb3a7a47bdd183c3c49d288274a1a8901`，仅 README，现有公开日志保留。
- AI：[PR #4](https://github.com/supdger/sand-ai/pull/4)，head `7eed93d07b2b39cfd8422ee10cd0cad2418caea8`；已合并 main `d2613353481e03c35f2b76247642f5ec6673f5ea`，仅 README 与公开预览基线 CHANGELOG，不含统一应用候选代码。
- 聚合：[PR #7](https://github.com/supdger/sand-plugins/pull/7)，head `f1aaa0c70c7fc5e044177d5ef045e23a5c1e5900`；已合并实际唯一默认分支 `codex/plugin-repository-baseline` 到 `fa5fcabcee3961b4095635b9817b4061987765e9`，仅 README。公开仓并无 main；原用户授权“总仓文档提交、推送、合并”没有限制分支，主控核实后沿用实际默认，不创建 main 或修改仓库设置。
- 四 PR 的 files 均只有上述文档，GitHub mergeState 为 CLEAN、statusCheckRollup 为空（没有待跑远端检查）；按固定 head 合并，未删除分支。每个创建后调用 attach_artifact 成功。
- SandAdmin Wiki：本批 Home、_Sidebar、changelog、plugin-updates、package-reference、sandai-plugin-maintenance 六页已提交并推送实际默认 master，公开提交 `c63b94429155ca82dd0101b6fe4d384f8a3a4d52`。
- 发布后重新 fetch 五个 remote，逐文件对比四源码仓 README / AI 日志与 Wiki 六页，公开默认内容均与独审候选一致。通过 CLI 获取公开 Wiki Home、_Sidebar、plugin-updates、package-reference、sandai-plugin-maintenance，HTTP 均 200 且包含预期版本导航或页面标题。此为公开文档读取核实，不是 GUI、真人使用或宿主业务验收。
- 已发布路径：[Core/Package/AI 版本更新](https://github.com/supdger/sandadmin/wiki/plugin-updates)；[Package 详细参考](https://github.com/supdger/sandadmin/wiki/package-reference)；[SandAI 源码维护](https://github.com/supdger/sandadmin/wiki/sandai-plugin-maintenance)。IAM/Workflow 两源码仓、两 Wiki 状态留由其执行者及主控记录，本线没有替其勾完成。
- 无安装包重发、宿主同步、数据库、服务或产品运行代码操作。首次创建 Core PR 时临时目录未找到 gh，改为已安装 CLI 绝对路径继续同授权操作；没有 GUI 或权限审核拒绝。


### 最终六仓文档与三 Wiki 公开收尾（2026-10-03，上海）

以下最终结果取代本任务较早阶段“未提交/未发布/pending”的文档状态；历史候选记录保留用于追溯，不代表最终仍待交付。原任务 3.2 的安装器真实版本详情 UI 验收仍未运行，本次不勾完成、不归档，也不将公开文档核实扩大为插件安装、升级、运行或业务验收。

- IAM：[PR #9](https://github.com/supdger/sand-iam/pull/9) 已合并 main `d74d71fecb5d9d61b1e18da96e66bce1883d5118`；IAM Wiki 已发布到 master `7b86c1596d2ef0a5c13221d9de2149c1b907283c`，候选 13 页。其执行者报告公开 README、CHANGELOG、Wiki Home、Changelog 与配置页 HTTP 200，逐页 remote 读取与独审候选一致；主控另独立通过 gh 读取 IAM README 并核实内容正确。
- Workflow：[PR #3](https://github.com/supdger/sand-workflow/pull/3) 已合并 main `655aeb72e1f4fb21b64912deb4e9f6b2d8d9190e`；Workflow Wiki 已发布到 master `7c8999a3f9841477b1a4e8c20981ffb09aeca4ce`，候选 4 页。其执行者报告公开 README、CHANGELOG、Wiki Home/Changelog HTTP 200，逐页 remote 读取与独审候选一致。
- IAM、Workflow 公开 README 各 34 行，与最终修订候选匹配。两仓没有 CI 配置，因此不称 CI 通过。此前 Workflow 暂缺日志的声明已进行最小复验，并按最终公开日志状态调整后交付。
- 新独立审查者 `solhigh__readme_standard_acceptance` 完成整个六 README 的规范验收并通过；Foundation 四仓及 SandAdmin Wiki 六页详见前段公开结果。六 README 保留中文版本更新入口，正文介绍使用者能力、源码/安装包边界、最小安装与可确认结果，详细版本和维护操作归对应既有 Wiki。
- 至此，本轮授权范围内的六源码仓文档提交/推送/合并、三个既有 Wiki 发布和公开文档读取核实均完成。产品包未重发；宿主、数据库与服务未操作；没有把文档交付冒充原安装器功能上线。
