# SandIAM

SandIAM 是基于 SandAdmin 的 PostgreSQL 插件，为 Sand 平台及接入应用提供通用的身份、组织、
应用登记、服务授权、策略判定、数据范围与审计能力。

它借鉴 Casdoor 的身份/组织/应用管理能力、Casbin 的策略判定模型，并补齐 SaiAdmin 3.x 数据范围
仅覆盖部分列表查询的不足。它不内置律序或其他项目的业务规则。

## 当前状态

`0.1.0` 是首个包含 P0 PostgreSQL schema 的插件包。P0 数据表与安装/升级/卸载生命周期已在隔离的原 SaiAdmin-PG 数据库验证；控制面与运行时身份上下文 API 已实现并完成 PostgreSQL 回滚事务验收。迁移到 SandAdmin 的必改引用和发布门槛见[更名通知](docs/development/sandadmin-rename-notice.md)。
Codex 已完成 IAM-01 与 IAM-02，冻结了 [P0 模型、API 与 SandAI Adapter](docs/development/sand-iam-p0-contract.md)；Cursor 已完成 U-01 页面壳。IAM-03 还需真实部署 signer 与已登录后台会话，才可完成 HTTP 成功路径验收。

## 目录

- `docs/product/`：SandIAM 产品需求；
- `docs/architecture/`：SandIAM 与 SandAI 的接入边界；
- `docs/development/`：开发入口、[任务看板](docs/development/sand-iam-task-board.md)、[协作约定](docs/development/sand-iam-pg-collaboration.md)、契约冻结和验收记录；
- `plugin/sand-iam/`：SandAdmin 后端插件包；
- `sandadmin-artd/`：管理端插件页面。

## 关键约束

- 仅 PostgreSQL；不引入 MySQL 的 `ENGINE=InnoDB`、`AUTO_INCREMENT`、`LAST_INSERT_ID()` 或 `sa_*` 表；
- 显示名 `SandIAM`，插件/路由 `sand-iam`，PHP `SandIam`，表与权限 `sand_iam_*`；
- SandIAM 提供能力，应用配置自身用户类型、资源和策略；
- 真实密钥只可写、轮换或撤销，绝不写入文档、日志或版本库。
