# sand-plugins 迁移记录

Sand 平台插件已经迁移到独立权威仓库：

| 插件 | 权威仓库 |
| --- | --- |
| SandIAM | [supdger/sand-iam](https://github.com/supdger/sand-iam) |
| SandWorkflow | [supdger/sand-workflow](https://github.com/supdger/sand-workflow) |
| SandAI 插件 | [supdger/sand-ai](https://github.com/supdger/sand-ai) |

SandAdmin 主体和统一插件目录位于
[supdger/sandadmin](https://github.com/supdger/sandadmin)。安装器按目录中每个插件的
`repository` 字段读取对应独立仓库 Release，不再从本仓或 SandAdmin 仓库分发插件包。

本仓只保留演示验收约定和历史交接材料，不再维护 SandAdmin demo 或发布三个插件源码。统一演示环境为
`/Users/code/project/sand_demo`，其中 `sandadmin-host.lock` 锁定 SandAdmin 完整源码 revision。迁移基线、拆分
revision 和发布摘要见 [独立仓库迁移记录](docs/independent-plugin-repositories.md)。

本地旧工作区如有未提交改动，应按插件分别审查并迁入对应独立仓库；不得把整个旧工作区
直接覆盖到新仓库。

## 版本更新

准备升级时，先查看对应组件最近公开版本的功能变化、修复与升级影响，再从更新页进入完整历史。各组件的适用范围和升级要求以对应版本页为准。

| 组件 | 版本更新与升级影响 | 完整公开历史 |
| --- | --- | --- |
| Sand Core | [版本更新（Wiki）](https://github.com/supdger/sandadmin/wiki/plugin-updates) | [完整更新日志](https://github.com/supdger/sand-core/blob/main/CHANGELOG.md) |
| Sand Package | [版本更新（Wiki）](https://github.com/supdger/sandadmin/wiki/plugin-updates) | [完整更新日志](https://github.com/supdger/sand-package/blob/main/CHANGELOG.md) |
| SandIAM | [版本更新（Wiki）](https://github.com/supdger/sand-iam/wiki/Changelog) | [完整更新日志](https://github.com/supdger/sand-iam/blob/main/CHANGELOG.md) |
| SandAI | [版本更新（Wiki）](https://github.com/supdger/sandadmin/wiki/plugin-updates) | [全部公开发布记录](https://github.com/supdger/sand-ai/releases) |
| SandWorkflow | [版本更新（Wiki）](https://github.com/supdger/sand-workflow/wiki/Changelog) | [全部公开发布记录](https://github.com/supdger/sand-workflow/releases) |
