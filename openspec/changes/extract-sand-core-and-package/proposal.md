## Why

Sand 平台基础能力即将从 SandAdmin 宿主内置源码拆为独立 Composer 包，需要在插件权威工作区建立 `sand-core` 与 `sand-package`，并让其源码布局、版本和发布边界与现有业务插件保持一致。

## What Changes

- 新建 `sand-core` 权威源码，包含 `server/` 核心后端与 `sandadmin-artd/` 核心前端源码。
- 新建 `sand-package` 权威源码，包含 `server/` 安装器后端与 `sandadmin-artd/` 安装器前端源码。
- 两个包均提供 Composer 元数据，并保留当前运行时命名空间以降低拆分风险。
- `sand-core` 提供显式、安全的前端源码发布入口，不执行前端依赖安装或编译。
- 更新本仓架构入口，将 SandPackage 的权威所有权从 SandAdmin 内置源码改为本仓 `sand-package`。
- 不修改现有 SandIAM、SandAI、SandWorkflow 的业务源码，不执行数据库迁移、服务启停、提交、推送或发布。

## Capabilities

### New Capabilities

- `composer-installed-sand-foundation`: 定义 Composer 基础包交付完整核心后端与匹配前端源码的行为。
- `sand-source-layout`: 定义 `sand-core`、`sand-package` 与业务插件统一的源码布局和权威所有权。

### Modified Capabilities

无。

## Impact

- 新增 `sand-core/`、`sand-package/`、Composer 元数据、前端发布工具和包级测试。
- 更新本仓 `AGENTS.md`、`README.md` 中的 SandPackage 所有权说明。
- SandAdmin 宿主切换由 `/Users/code/project/sandadmin` 的同名 OpenSpec change 独立负责。
