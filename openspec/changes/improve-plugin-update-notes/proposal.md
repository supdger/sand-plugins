## Why

管理员目前难以从插件主页与版本详情找到明确的版本更新内容，无法据此判断功能变化与升级影响。用户要求直接弥补缺口，补齐证据支持的日志并把安装管理入口与日志内容连接起来。

## What Changes

- 给五个源码单元在 README 靠前提供独立“版本更新”栏目，提供明确的中文 Wiki 更新页与完整历史及持续发行入口；最近公开版本正文及升级影响集中维护在 Wiki；根 README 提供聚合入口。
- 现有 Wiki 的首页和侧栏明确提供“版本更新”，版本页直接展示最近公开版本变化与升级影响。Core、Package、AI 沿用现有 SandAdmin Wiki 的 plugin-updates；IAM、Workflow 沿用各自独立 Wiki 的 Changelog。
- 版本详情可以读取所选、已校验版本包根目录 CHANGELOG.md；未提供日志时显示明确缺口。
- 构建包携带 CHANGELOG.md，目录记录默认提取当前版本章节作为 notes，可显式覆盖；已有历史目录说明不重写。
- 保留现有 README、IAM 与 Package 日志条目，只记录可核实版本或未发布候选，不虚构历史差异和发布日期。

## Capabilities

### New Capabilities

- `plugin-update-notes`: 插件日志查阅、发行包携带和目录版本说明生成。

### Modified Capabilities

无。

## Impact

影响五个源码单元的日志与入口、SandPackage document API 与版本详情页，以及包构建工具的日志资产和目录 notes。API 的现有 README 默认行为保留；无数据库、宿主同步或服务影响。初轮授权包含源码/文档编辑及必要本地验证；本轮用户追加授权覆盖六公开仓的本批文档提交、推送、合并，以及三个既有 Wiki 发布。此文档授权不包含安装包重发、产品代码发布、宿主同步、数据库或服务操作。
