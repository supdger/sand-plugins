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
`/Users/code/project/sand_demo`，其中 `server/composer.lock` 锁定 SandAdmin 版本。迁移基线、拆分
revision 和发布摘要见 [独立仓库迁移记录](docs/independent-plugin-repositories.md)。

本地旧工作区如有未提交改动，应按插件分别审查并迁入对应独立仓库；不得把整个旧工作区
直接覆盖到新仓库。
