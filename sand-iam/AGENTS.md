# SandIAM 插件入口

先读 `README.md` 与 `docs/product/sand-iam-product-requirements.md`。

- 这是面向 SandAdmin 的 SandIAM 插件源码；只使用 PostgreSQL，表前缀固定为 `sand_iam_*`。
- 插件目录名和路由段为 `sand-iam`，PHP 命名空间为 `plugin\SandIam`。
- SandIAM 是通用身份、策略与数据授权能力；不得写入律序或其他应用的业务用户类型、资源或规则。
- 业务应用配置自己的用户类型、资源和策略；SandAI 仅消费 SandIAM 验证后的调用上下文。
- 在创建任何表、路由或权限代码前，先在 `docs/development/sand-iam-development-entry.md` 冻结其归属、名称、主键、边界、接口和验收条件。

## 任务与协作

- 任务状态唯一来源：[SandIAM 任务看板](docs/development/sand-iam-task-board.md)
- 协作边界：[PostgreSQL 协作约定](docs/development/sand-iam-pg-collaboration.md)
- Codex 独占：`plugin/sand-iam/`（PHP、SQL、菜单/路由、契约）
- Cursor 独占：`sandadmin-artd/src/views/plugin/sand-iam/`
- 未冻结字段不得猜测实现。业务拒绝用 `plugin\sandadmin\exception\ApiException`，显式传 `400`/`401`。
