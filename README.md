# sand-plugins

Sand 平台组件的索引仓库。本仓不再分发插件包，早期预发布资产仅作历史追溯；安装请使用各组件仓库的 Release。每个组件都有自己的公开仓库、发行页和文档入口。

## 组件与公开仓库

| 组件 | 类型 | 公开仓库 | 发行 |
| --- | --- | --- | --- |
| Sand Core | SandAdmin 核心 Composer 包 | [supdger/sand-core](https://github.com/supdger/sand-core) | [Releases](https://github.com/supdger/sand-core/releases) |
| Sand Package | 插件安装器 Composer 包 | [supdger/sand-package](https://github.com/supdger/sand-package) | [Releases](https://github.com/supdger/sand-package/releases) |
| SandIAM | 业务插件（ZIP） | [supdger/sand-iam](https://github.com/supdger/sand-iam) | [Releases](https://github.com/supdger/sand-iam/releases) |
| SandAI | 业务插件（ZIP） | [supdger/sand-ai](https://github.com/supdger/sand-ai) | [Releases](https://github.com/supdger/sand-ai/releases) |
| SandWorkflow | 业务插件（ZIP） | [supdger/sand-workflow](https://github.com/supdger/sand-workflow) | [Releases](https://github.com/supdger/sand-workflow/releases) |

SandAdmin 主体和统一插件目录位于 [supdger/sandadmin](https://github.com/supdger/sandadmin)。安装器按目录中每个插件的 `repository` 字段读取对应仓库的 Release，不从本仓或 SandAdmin 仓库分发插件包。

## 版本更新

升级前先看对应组件最近公开版本的功能变化、修复和升级影响，再从更新页进入完整历史。各组件的适用范围和升级要求以其版本页为准。

| 组件 | 版本更新与升级影响 | 完整公开历史 |
| --- | --- | --- |
| Sand Core | [版本更新（Wiki）](https://github.com/supdger/sandadmin/wiki/plugin-updates) | [完整更新日志](https://github.com/supdger/sand-core/blob/main/CHANGELOG.md) |
| Sand Package | [版本更新（Wiki）](https://github.com/supdger/sandadmin/wiki/plugin-updates) | [完整更新日志](https://github.com/supdger/sand-package/blob/main/CHANGELOG.md) |
| SandIAM | [版本更新（Wiki）](https://github.com/supdger/sand-iam/wiki/Changelog) | [完整更新日志](https://github.com/supdger/sand-iam/blob/main/CHANGELOG.md) |
| SandAI | [最近发行说明](https://github.com/supdger/sand-ai/releases) | [完整更新日志](https://github.com/supdger/sand-ai/blob/main/CHANGELOG.md) |
| SandWorkflow | [版本更新（Wiki）](https://github.com/supdger/sand-workflow/wiki/Changelog) | [完整更新日志](https://github.com/supdger/sand-workflow/blob/main/CHANGELOG.md) |

## 本仓内容

- [独立仓库迁移记录](docs/independent-plugin-repositories.md)：各组件迁出本仓的基线、拆分 revision 与发布摘要。
- [SandAdmin demo 约定](docs/sandadmin-demo.md)：演示与验收宿主的锁定方式。
- [宿主问题提交](docs/sandadmin-requests/README.md)：向 SandAdmin 提交跨插件缺陷的模板与已有记录。

## 许可与反馈

本仓采用 MIT 许可证，全文见 [LICENSE](LICENSE)。各组件的许可以其仓库内的 LICENSE 为准：[Sand Core](https://github.com/supdger/sand-core/blob/main/LICENSE) 与 [Sand Package](https://github.com/supdger/sand-package/blob/main/LICENSE) 为 MIT，[SandAI](https://github.com/supdger/sand-ai/blob/main/LICENSE) 为 MIT，[SandIAM](https://github.com/supdger/sand-iam/blob/main/LICENSE) 为 MIT；[SandWorkflow](https://github.com/supdger/sand-workflow/blob/main/NOTICE) 按目录划分，后端为 MIT，前端流程设计器改自 BeeFlow、为 AGPL-3.0，仓库整体按 AGPL-3.0 分发，详见其 NOTICE。

组件的问题与建议请提交到对应仓库的 Issues；与本仓索引或交接文档有关的问题提交到 [Issues](https://github.com/supdger/sand-plugins/issues)。
