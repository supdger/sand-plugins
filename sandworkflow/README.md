# SandWorkflow

SandWorkflow 是 SandAdmin 的 PostgreSQL 工作流插件，提供流程设计、发布、发起、审批、待办、抄送和流程数据查询。流程管理员设计与发布流程，发起人提交申请，审批人处理待办。

**当前公开发行为 1.0.8 修正版，用于修正 1.0.7 预览版的宿主兼容声明。** 该版本尚未在真实 SandAdmin 宿主完成安装、业务闭环、升级和卸载验收；使用前请在隔离环境自行验证，未验收范围见[版本与验收范围](https://github.com/supdger/sand-workflow/wiki/SandWorkflow-Versions)。

## 版本更新

[版本更新](https://github.com/supdger/sand-workflow/wiki/Changelog)说明近期变化与升级影响；[完整更新日志](CHANGELOG.md)保留版本记录。发布状态与安装资产见 [Releases](https://github.com/supdger/sand-workflow/releases)。

## 源码与安装包

直接使用请下载 Release 附件中的完整插件 ZIP，并核对来源、宿主范围和校验材料。GitHub 的 `Source code (zip)` 是源码压缩包，不是插件安装包。

本仓包含插件后端、管理端源码与生命周期 SQL；开发和构建见[开发与发布包](https://github.com/supdger/sand-workflow/wiki/SandWorkflow-Development)。

## 安装与第一次流程

需要已有的 SandAdmin PostgreSQL 宿主（`>=0.1.0`）和 SandPackage 安装器，由宿主管理员安装插件并提供流程管理员、发起人、审批人账号及相应权限。兼容范围、包校验和安装步骤见[安装、升级与卸载](https://github.com/supdger/sand-workflow/wiki/SandWorkflow-Installation)。已有数据先备份，不能通过重装修复。

1. 通过宿主插件管理安装完整包，按安装指南构建、激活管理端，并授予流程相关权限。
2. 流程管理员创建分组与流程定义，配置表单和一个审批节点，发布流程。
3. 发起人提交一条申请；审批人在待办中通过，再查看实例详情与流转记录。

第一条流程的可确认结果是：实例显示“已通过”，发起人、审批人和流程管理员能在各自授权入口查询本次记录。具体操作见[完成一条最小流程](https://github.com/supdger/sand-workflow/wiki/SandWorkflow-First-Workflow)。

## 使用文档

- [Wiki 首页](https://github.com/supdger/sand-workflow/wiki)：安装、使用与维护入口。
- [设计与发布](https://github.com/supdger/sand-workflow/wiki/SandWorkflow-Design-and-Publish)、[处理审批与查询](https://github.com/supdger/sand-workflow/wiki/SandWorkflow-Tasks-and-Data)：日常流程操作。
- [角色与权限](https://github.com/supdger/sand-workflow/wiki/SandWorkflow-Permissions)、[故障排查](https://github.com/supdger/sand-workflow/wiki/SandWorkflow-Troubleshooting)：授权与故障处理。
- [版本与验收范围](https://github.com/supdger/sand-workflow/wiki/SandWorkflow-Versions)：兼容条件和已知验证范围。

## 许可与反馈

流程设计器基于 [workflow-web](https://github.com/zhangjinlibra/workflow-web) 修改，适用 [GNU AGPL v3](LICENSE)；来源和修改说明见 [NOTICE](NOTICE)。问题与建议请提交到 [Issues](https://github.com/supdger/sand-workflow/issues)。
