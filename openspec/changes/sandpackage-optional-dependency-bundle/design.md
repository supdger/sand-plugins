# Design: sandpackage-optional-dependency-bundle

## Context

- 提供方：`sand-package`（权威源码 `sand-plugins` monorepo `sand-package/`，宿主侧 vendor 形态 `supdger/sand-package` ≥0.2.14）。调用方/触发方：`sand-ai` 0.3.0（声明 `plugin_dependencies.sand-iam=0.8.4` + exact sha256）。
- 现状实现：`verifyBundles`（`InstallLogic.php`）在解包/上传预检对声明依赖强制要求 `dependencies/<app>-<version>.zip` 存在且 sha256 相等；`preparePluginDependencies` 把 bundle 解压为依赖候选（fresh 安装或 existing-schema 重接清单 `existing-schema.json` 提供方）。
- 已确认事实（2026-11-01 真实宿主验证记录）：已注册依赖按最低版本语义满足；bundle 只在"干净库一键安装"与"现有 schema 重接清单"两个场景被实际消费；"依赖已在宿主注册"的升级场景不触碰 bundle 字节。SandAI 0.3.0 因删 bundle 导致发布资产不可安装（已用官方 builder 重建修复）。
- 跨插件论证要素（`docs/cross-plugin-changes.md`）：需求方为 SandAI 发布链路与全部声明依赖插件；锁定目标为 sand-package ≥0.2.14 源码；必要性证据=缺 bundle 时无替代满足路径导致发布资产不可安装 + 依赖发版节奏强制跟随；通用性证据=任何声明 `plugin_dependencies` 的插件共享同一痛点，方案不含调用方专有数据。

## Goals / Non-Goals

**Goals:**
- 依赖解析支持多路径，解除"声明必有 bundle"硬绑定，不削弱 bundle 存在时的身份校验强度。
- 无 bundle 包与有 bundle 包在同等条件下可安装；老宿主与已发布带 bundle 包行为不变。
- 拒绝信息可诊断：指明缺失依赖、版本约束与可行路径。

**Non-Goals:**
- 不改变 `plugin_dependencies` 声明格式与最低版本比较语义。
- 不改 `existing-schema.json` 重接清单机制；无 bundle 时重接仍要求对应依赖包另行提供。
- 不处理 npm/前端依赖；不引入依赖自动联网下载（安装器保持离线可控）。

## Decisions

1. **解析顺序固定为"注册→候选→bundle→引导上传"**：已注册依赖（最低版本比较）最先判断，避免对已满足宿主做多余工件校验；bundle 校验仅在工件实际存在且被采用路径需要时进行身份校验。备选"先 bundle 后注册"被否决——会在已升级宿主上强制降级依赖。
2. **bundle 校验语义收敛**：包内携带 bundle 时仍执行 version+sha256 exact 校验（发布方声明的工件身份必须诚实），但只在依赖未被已注册/已上传路径满足时才被消费。备选"有 bundle 才校验、无则跳过"被部分采纳——存在即校验保证声明诚实。
3. **无 bundle 引导路径**：依赖未满足时上传响应返回结构化缺失信息（依赖名、最低版本、所需路径），管理端可据此引导上传依赖包后重试安装，不要求重传主包。
4. **卸载保护联动**：依赖满足源为已注册插件时，`uninstall` 检查声明矩阵阻止移除仍被依赖的插件，错误指明依赖方——与现有依赖卸载保护同构扩展。
5. **最小变更面**：只动 `sand-package` 的解析/校验/错误模型与文档；`existing-schema` 重接、候选撤回、生命周期日志不动。

## Risks / Trade-offs

- **风险**：无 bundle 发布方可能把"依赖未装"的责任下沉到管理员手工流程 → 由引导上传路径与清晰错误缓解；文档须明确推荐仍提供 bundle 作为干净库最佳体验。
- **风险**：已注册依赖语义（最低版本）高于 bundle 精确版本，可能造成宿主内依赖与包构建期声明不一致 → 可接受，因该语义已在现行版本存在，本变更只扩展满足路径。
- **Trade-off**：错误信息需携带结构化字段供前端渲染引导 → 增加 ApiException details 契约，属最小提供方契约修改。

## 验收与回滚

- 验收：真实宿主分别验证三路径（已注册依赖放行/无 bundle 拒绝引导/bundle 消费）+ 回归带 bundle 老包；卸载保护负例。
- 回滚：变更仅安装器逻辑，回滚=恢复 `verifyBundles` 硬约束分支；已安装插件不受影响。

## 审查

- 待 `sand_plugins` 负责方安排未参与实现的审查者按 `docs/cross-plugin-changes.md` 核对后通过；通过前不修改 `sand-package` 源码。
