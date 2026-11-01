# Proposal: sandpackage-optional-dependency-bundle

## Why

现行 SandPackage 安装协议要求：只要插件在 `config.json` 声明 `plugin_dependencies`，包内 `dependencies/` 目录就必须携带与声明 version/sha256 逐字节一致的依赖插件 ZIP，否则上传即被 `verifyBundles` 拒绝。该约束把"声明依赖"与"随包携带工件"绑定，已造成真实发布事故：SandAI 0.3.0 为压体积删去 `dependencies/` 后整个 Release ZIP 不可安装。同时随包工件把发布方钉死在依赖方的发版节奏上（依赖每升一版，包内副本即过期），且对"依赖已在宿主注册"的主流升级场景收取不变体积税。

## What Changes

- SandPackage 依赖解析改为按序多路径：已注册依赖（installed，按最低版本语义满足）→ 已上传依赖候选 → 随包 bundle → 引导上传依赖包；任一满足即放行，均不满足才拒绝。
- `plugin_dependencies` 声明保留 exact version/sha256 身份语义：bundle 存在时仍逐项 sha256 校验，但不再要求它必须存在。**BREAKING**：`verifyBundles` 的"声明必有 bundle"硬约束解除为"声明可用任一路径满足"。
- 随包 bundle 保留为可选能力：全新干净库的"一键装全家"场景不受影响；`existing-schema.json` 重接清单仍由 bundle 提供时沿用现有逻辑。
- 安装器在缺 bundle 且依赖未满足时给出明确错误：列出缺失依赖、所需最低版本、可走的满足路径（上传依赖包/重放随包 bundle），替代当前的裸"依赖工件缺失或摘要不匹配"。
- 老安装器（不认识该协议的宿主，如 SandPackage 0.2.4）行为不变：依赖仍按宿主流程手动安装。

## Capabilities

### New Capabilities
（无）

### Modified Capabilities
- `plugin-install-lifecycle`: 依赖声明与满足路径的验收要求变化——"声明依赖必须随包携带 exact 工件"改为"声明依赖可按已注册/已上传候选/随包 bundle/引导上传任一路径满足"；bundle 存在时的 sha256 身份校验要求不变。

## Impact

- `sand-package`：`verifyBundles`/`preparePluginDependencies`/上传预检错误模型、`InstallLogic` 依赖解析顺序、卸载保护联动（依赖满足源为已安装插件时不得卸载）。
- 插件发布方（sand-ai、sand-license 等）：可在源仓维持 `dependencies/` 为空、构建期注入 bundle，也可完全不携带 bundle；两种发行形态均须可安装。
- 兼容：bundle 存在时的行为（候选上传、existing-schema 重接清单）保持现状；无 bundle 的新路径不破坏已发布带 bundle 的包。
- 消费宿主：SandAdmin 0.2.4 等老宿主不感知本变更；升级到新 SandPackage 的宿主获得可选 bundle 能力。
