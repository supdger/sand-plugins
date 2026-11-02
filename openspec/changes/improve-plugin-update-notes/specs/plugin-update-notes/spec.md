## Purpose

让维护者按可核实版本维护功能变化与升级影响，让管理员从源码首页及安装管理的所选版本入口找到对应更新日志，避免将未知历史、未发布候选或缺失日志误当正式版本说明。

## ADDED Requirements

### Requirement: Discoverable source update history

五个源码单元 SHALL 在 README 靠前设置独立“版本更新”栏目，包含中文 Wiki 更新页入口、根 CHANGELOG.md 完整历史与持续发行入口；README SHALL 介绍使用者能力、源码/安装包区别、真实最小安装用法及可确认结果，固定版本变化和升级正文 SHALL 在既有 Wiki 维护，根仓 README SHALL 提供聚合入口。既有 Wiki 的首页和侧栏 SHALL 明确提供版本更新导航；对应更新页 SHALL 直接展示最近公开版本变化及升级影响，并连接实际存在的完整历史。日志 MUST 区分可核实发布记录与未发布候选，描述功能、修复与升级影响，不虚构发布日期或未知版本差异。

#### Scenario: Administrator checks upgrade impact

- **WHEN** 管理员从任一单元 README 查找版本变化
- **THEN** 可以沿日志入口看到可核实的版本变化、候选状态与受影响的升级条件

#### Scenario: Reader does not know the changelog filename

- **WHEN** 管理员仅知道组件名称，从其 README 或现有 Wiki 首页查找更新
- **THEN** 无需猜测英文文件名即可找到“版本更新”栏目或导航，在更新页读到最近公开版变化、升级影响、稳定/预发布/未发布区别，并访问完整历史

### Requirement: Version-specific package changelog

版本详情 SHALL 提供更新日志入口。document API SHALL 接受可选 type=changelog 并只读取所选版本经过既有摘要校验的 ZIP 根 CHANGELOG.md，默认类型 MUST 保持 README 行为。

#### Scenario: Changelog exists in selected version

- **WHEN** 所选版本包通过摘要校验且根目录有 CHANGELOG.md
- **THEN** 更新日志入口返回该包中的日志内容

#### Scenario: Historical package lacks changelog

- **WHEN** 所选历史版本包中没有根 CHANGELOG.md
- **THEN** 返回空日志并在界面说明该版本发布包未提供更新日志，不从最新源码补造历史

### Requirement: Release notes derive from matching history

构建器 SHALL 将存在的根 CHANGELOG.md 带入 ZIP，并默认提取与 info.ini version 匹配的章节作为目录 notes。显式 notes SHALL 优先，notes MUST 不超过既有 8000 字节限制。

#### Scenario: Matching version chapter

- **WHEN** 构建版本与日志章节一致且未显式传入 notes
- **THEN** 目录 notes 包含该章节功能内容而排除 Unreleased 和其他版本内容

#### Scenario: No matching chapter

- **WHEN** 日志缺失或没有当前版本章节且未显式提供 notes
- **THEN** 不将其他版本或未发布条目用作该版本说明，保留空值

#### Scenario: Explicit notes override

- **WHEN** 维护者明确提供 notes
- **THEN** 使用该说明并检查长度，不覆盖既有历史目录条目
