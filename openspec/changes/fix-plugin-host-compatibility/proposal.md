## Why

SandAdmin 版本进入 `0.1.x` 后，插件需要声明明确最低宿主版本。现有 SandPackage 前端和 Legacy 升级校验只理解主版本通配符，普通安装后端又在宿主兼容检查之前处理插件依赖，无法一致拒绝不兼容候选。

## What Changes

- 增加最低宿主版本及可选包含上界的通用范围表达式，保留历史主版本、次版本通配符和分支并集。
- 统一管理页面与后端的兼容结果，普通安装在处理依赖、数据库脚本和部署载荷前拒绝不兼容候选。
- 使用共享行为数据集验证前后端，以及已有 Legacy 升级预检。

## Capabilities

### New Capabilities

- `plugin-host-version-support`: 插件声明的宿主兼容范围及安装前拒绝行为。

### Modified Capabilities

无。

## Impact

仅 SandPackage 提供方的兼容匹配器、前端安装页面、普通安装入口及 Legacy 已有升级兼容检查。没有新增依赖，不改业务插件实现、安装恢复机制、数据库结构或服务。SandAdmin catalog、业务插件元数据及各自发布检查由消费侧改动单独承担。
