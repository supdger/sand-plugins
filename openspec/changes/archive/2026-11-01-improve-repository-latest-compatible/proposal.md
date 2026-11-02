## Why
插件仓库默认取清单第一项且返回页面不刷新，管理员无法直接看到适配当前宿主的最高版本。

## What Changes
- 每次进入仓库刷新远端，加载和失败时撤下过期操作。
- 卡片推荐最高兼容版本，清晰呈现已安装版本与目标版本，用户自行决定安装或升级。
- 历史与不兼容版本留在详情，并显示预发布标识。

## Capabilities
### New Capabilities
- `repository-latest-compatible`: 最新兼容版本推荐与仓库刷新。
### Modified Capabilities
无。

## Impact
仅 SandPackage 通用仓库后端和管理端；不修改目录、发布包、业务插件、数据库或运行宿主。
