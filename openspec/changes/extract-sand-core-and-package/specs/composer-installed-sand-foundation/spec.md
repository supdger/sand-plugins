## Purpose

定义标准 SandAdmin 宿主和已有 Webman 项目如何通过 Composer 获得版本一致的后台核心、插件安装器及可直接用于开发的管理端前端源码。

## ADDED Requirements

### Requirement: Composer 安装完整核心源码
`sand-core` Composer 包 SHALL 同时包含统一布局下的核心后端源码与 `sandadmin-artd` 前端源码，且不得要求消费者从另一个仓库补取匹配版本的核心前端。

#### Scenario: 已有 Webman 安装核心
- **WHEN** 开发者在受支持的已有 Webman 项目中安装 `supdger/sand-core`
- **THEN** 已安装包同时提供可加载的 SandAdmin 核心后端源码和同版本的 `sandadmin-artd` 前端源码

### Requirement: 发布可编辑前端源码
`sand-core` SHALL 提供显式、可重复执行的前端源码发布入口，将包内 `sandadmin-artd` 源码发布到开发者指定的非 `vendor` 目录，并 SHALL 拒绝静默覆盖目标目录中的未知修改。

#### Scenario: 首次发布前端源码
- **WHEN** 开发者指定一个不存在或为空的目标目录执行前端源码发布
- **THEN** 系统将当前 `sand-core` 版本的 `sandadmin-artd` 源码完整发布到该目录

#### Scenario: 目标包含未知修改
- **WHEN** 开发者向包含非当前发布基线修改的目标目录再次发布
- **THEN** 系统停止发布并报告冲突，不静默覆盖开发者源码

### Requirement: 标准宿主只声明基础包
SandAdmin 标准宿主 SHALL 通过 Composer 声明 `sand-core` 与 `sand-package`，不得继续以内置核心或内置插件安装器目录作为长期权威源码。

#### Scenario: 干净宿主安装依赖
- **WHEN** 开发者在干净的 SandAdmin `server/` 中执行 Composer 安装
- **THEN** Webman 能从锁定版本的 `sand-core` 与 `sand-package` 加载后台核心和插件管理能力

### Requirement: Composer 安装不隐式执行破坏性生命周期
安装或更新 `sand-core`、`sand-package` SHALL NOT 隐式创建数据库、执行数据库迁移、启动或重启服务。

#### Scenario: Composer 安装基础包
- **WHEN** Composer 完成基础包安装或更新
- **THEN** 仅完成依赖安装与安全的源码发布准备，不执行数据库写入或服务启停

### Requirement: 核心前后端版本一致
宿主使用的核心后端与核心 `sandadmin-artd` 源码 MUST 来自同一 `sand-core` 发布版本，并提供可核对的版本信息。

#### Scenario: 核对发布版本
- **WHEN** 开发者检查已安装核心后端和已发布前端源码
- **THEN** 两者报告相同的 `sand-core` 版本和来源标识

