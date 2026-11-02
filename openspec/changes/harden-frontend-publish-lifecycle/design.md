## Context

宿主首装链路（`composer install` → webman `support\Plugin::install` → 各包 `Install::install()` → `FrontendPublisher::publish()` + `installByRelation()`）经独立审计确认存在结构性缺陷：

- 前端"发布产物"被 git 提交而"发布状态"（清单）被 `.gitignore` 排除，所有权模型自相矛盾，`31d5eea` 删清单后 fresh clone 必炸。
- `post-package-install` 阶段抛异常 = Composer 中止、`installed.json` 已提交、autoload 未生成；重跑零操作"成功"且钩子不再触发——失败不可重入。
- `Install::install()` 先前端后后端，前端拒绝连累后端载荷。
- `copyTree` 后写清单两段式非原子；`uninstall`/`update` 用 `remove_dir`/`copy_dir(overwrite=true)` 无差别删改宿主文件（现网已有 3 个分叉文件将被静默覆盖）。
- `frontendTarget()` 按目录名启发式，名为 `server` 的项目会写到项目外。
- 排除规则在 `FrontendPublisher::excluded`、`SandSystemUpdateReleaseTools::excluded`、`archive.exclude`、`.gitattributes` 四套口径各自漂移；`.husky`、`.vscode`、测试夹具随载荷发布。
- 权威源码单元此前无 git（已在本轮治理中改为 GitHub 仓 clone），已发布 0.2.4/0.2.5 与本地工作树的漂移靠人工发现。

约束：清单格式需向后兼容；现有"拒绝覆盖用户修改"的保护语义必须保留，只放宽误杀分支；webman `support\Plugin` 钩子签名固定，改动只能在各包 `Install`/`FrontendPublisher` 内部；system-update 通道（`system-update-worker.php`）与 composer 通道共用判定语义，避免再造第三套实现。

## Goals / Non-Goals

**Goals:**

- fresh clone `composer install` 100% 走完并产出可启动宿主；
- 发布钩子任何一步失败都可重入恢复，重跑 `composer install` 不会"静默成功但缺载荷"；
- 不损毁宿主已有文件（消费者修改、未知文件、`config/` 定制）；
- 发布面收敛为单一排除规则源，开发工具链不出现在产物中。

**Non-Goals:**

- 不重写 system-update-worker 的恢复体系（仅让 composer 通道复用其判定/排除语义，不在本变更内合并两套 worker）；
- 不改变 `sandadmin-artd` 作为消费者前端工程源码的定位（README 承诺）；
- 不处理 ZIP 业务插件（SandIAM/SandAI/SandWorkflow）的安装生命周期（`plugin-install-lifecycle` 已覆盖）；
- 宿主前端是否继续 git 提交的"所有权终态"不在本变更裁决——见 Decisions D8，机制改造后两种模式都安全。

## Decisions

### D1：冲突判定从"存在性"改为"内容寻址"

无清单目标目录按发布源 sha256 逐文件比对：全同 → 写清单收养；有不同 → 拒绝并列出差异文件。这使"宿主把发布产物再提交"（当前仓库模型）成为合法场景，同时保留对真实本地修改的保护。备选"清单入库"（把 manifest 提交进宿主仓）单独不能覆盖消费者手改和外部 webman 宿主场景，作为补充手段并行采纳（宿主已恢复提交两份清单）。

### D2：后端载荷先行，前端失败降级为告警

`Install::install()/update()` 内部顺序：`installByRelation()` → `FrontendPublisher::publish()`（包 try/catch → STDERR 警告 + 恢复命令）。理由：后端载荷决定宿主能否启动，前端是工程源码可后补；Composer 钩子内异常 = 不可重入中止（审计已验证），告警是唯一不拖死安装的做法。sand-package 的"core 基线清单缺失"同样降级（缺 core 清单时跳过前端并告警，等 core 发布或人工 `sand:publish`）。

### D3：两阶段清单（`.pending` + rename）

`writeManifest` 拆成 `begin`（写 `.sand-*-source-manifest.json.pending`，含目标文件哈希集）→ copy → `commit`（rename）。启动时见 pending 则按其哈希判定补写/重做。rename 在同文件系统内原子，覆盖"copy 完成即断电"窗口。

### D4：删除与卸载按清单驱动，config/ 只写一次

升级删除集 = 旧清单文件 − 新载荷文件，逐项校验旧哈希未变才 unlink（复用 worker `assertFrontendCandidates`/obsolete 清理语义）。`uninstall()` 按清单删文件、未知文件保留并报告；后端 `config/` 目录"仅首次安装写入"（存在即跳过，升级不覆盖宿主配置）。

### D5：重入挂点用全局 `post-install-cmd`，逃生门用环境变量

`post-package-*` 只在包安装/更新时触发，不是重入点；幂等 `sand:publish` 脚本挂宿主 `post-install-cmd`/`post-update-cmd`（每次 install 都执行，已发布则 no-op）。`SAND_FRONTEND_PUBLISH` 读取顺序：`skip`/`force`/默认；`force` 用于清单损坏或强制重发布。

### D6：`extra.sandadmin.frontend-path` 显式声明目标

宿主 `composer.json` 写 `extra.sandadmin.frontend-path`（缺省回退现启发式），发布前 `realpath` 边界校验 + 逐段 `is_link` 检查（对齐 worker `safePath()`）。composer.json extra 是社区标准的包-宿主协商通道。

### D7：排除规则单一来源

`sand-core` 提供 `PublishExclusions::all()` 常量数组（唯一来源），四个消费点（publisher、release tools、`.gitattributes`、`composer.json archive.exclude`）由生成脚本或断言测试对齐；不一致即构建失败。载荷瘦身随此一并落地（`.husky`/commitlint/`prepare`/fixtures 移出发布面；夹具移到包根 `tests/`）。

### D8：前端所有权维持"双轨、机制兜底"，不裁决终态

候选 (a) 宿主仓继续提交前端（git 主、composer 校验收养）；(b) 前端完全由 composer 发布、从宿主仓移除（与 `server/plugin/` 同模型）。D1 收养判定落地后两种模式都安全可用；(a) 保持现有开发体验与文档不变，选 (a) 为默认，(b) 留作后续独立的仓库布局决策（需改 clone 后体验与文档，超出本变更范围）。

## Risks / Trade-offs

- **降级告警被忽略**：前端失败不阻塞 install 后，用户可能看不到警告。缓解：警告固定前缀 `[sandadmin]`、附恢复命令；`/core/install` 页可在清单缺失时提示运行 `sand:publish`。
- **收养判定扩大信任面**：攻击者预置与源相同的文件也会被收养——与源相同即无实际风险；内容不同的仍被拒。
- **清单驱动删除误伤**：删除前按旧哈希校验未修改才删，已修改保留并告警；`.pending`/清单损坏走 `force` 通道。
- **双轨所有权的遗留风险**：宿主前端与包载荷仍可能漂移；用 `tools/assert-frontend-parity.php`（本变更任务）做 parity 门禁兜底。
- **兼容**：`skip|force` 默认不变；旧清单照常读取；非 `server` 目录名项目的行为由 extra 显式化，原启发式保留为缺省。
