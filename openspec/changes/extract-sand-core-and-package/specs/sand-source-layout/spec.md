## Purpose

定义 Sand 核心、插件安装器和业务插件一致的源码目录、分发方式与权威所有权，避免 `src`、宿主副本和插件目录混用造成来源不清。

## ADDED Requirements

### Requirement: 统一顶层源码布局
每个同时包含后端和管理端前端的 Sand 包 SHALL 使用顶层 `server/` 与 `sandadmin-artd/`，不得只为部分包引入含义不同的顶层 `src/`。

#### Scenario: 检查 Sand 包源码
- **WHEN** 开发者检查 `sand-core`、`sand-package` 或业务插件源码
- **THEN** 后端载荷位于 `server/`，管理端前端载荷位于 `sandadmin-artd/`

### Requirement: 分发方式与能力类型匹配
`sand-core` 与 `sand-package` SHALL 作为 Composer 基础包分发；SandIAM、SandAI、SandWorkflow 等业务插件 SHALL 继续作为由 SandPackage 管理的 ZIP 插件分发。

#### Scenario: 安装基础能力
- **WHEN** 开发者安装后台核心或插件安装器
- **THEN** 使用 Composer 解析和锁定版本

#### Scenario: 安装业务插件
- **WHEN** 管理员安装 SandIAM、SandAI 或 SandWorkflow
- **THEN** 使用包含后端、前端和生命周期文件的 ZIP，通过 SandPackage 执行受控安装

### Requirement: 权威源码与宿主消费分离
每个独立 Sand 包 SHALL 有且仅有一个权威源码位置；SandAdmin 宿主中的安装结果或验收副本 SHALL NOT 成为反向同步的权威来源。

#### Scenario: 修改独立包
- **WHEN** 开发者修改 `sand-core` 或 `sand-package`
- **THEN** 修改发生在对应权威源码目录，并由宿主通过依赖或受控同步消费

### Requirement: 兼容迁移保留可验证回滚
从内置目录迁移到独立包时 SHALL 保留可核对的文件映射、版本锁定和回滚基线，且在新依赖链通过验证前不得删除唯一可工作的源码。

#### Scenario: 新包安装验证失败
- **WHEN** Composer 化后的核心或安装器未通过等价验证
- **THEN** 迁移停止，原内置源码保持可恢复，宿主不得被标记为完成迁移

