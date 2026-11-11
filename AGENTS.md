# AGENTS

先读 [README.md](README.md)。本仓只保留迁移记录、外部 SandAdmin demo 约定和历史验收材料。

三个插件的源码修改必须在各自独立仓库进行：

- SandIAM：`supdger/sand-iam`
- SandWorkflow：`supdger/sand-workflow`
- SandAI 插件：`supdger/sand-ai`

禁止在本仓重新创建同名插件源码目录或发布插件 Release。演示环境统一使用
`/Users/code/project/sand_demo`，按 [SandAdmin demo](docs/sandadmin-demo.md)通过 Composer
锁定 SandAdmin release；实际数据库、服务和插件生命周期
操作仍需任务级授权。

## 本仓内容与目录

- `docs/`：独立仓库迁移记录、demo 约定、宿主问题提交模板（`docs/sandadmin-requests/`）
- `.cursor/rules/`：协作约定（如 SandIAM 前后端分工）
- 本仓无源码、无构建配置、无测试

## 命名与注释规范（通用原则）

本仓无代码；新增文档、示例代码与引用片段同样遵守统一叫法，组件源码仓另有各自 AGENTS.md。

- 统一叫法：`dept`、`file`、`create_time`/`update_time`/`delete_time`、`status` = 启停、`state` = 流转、`announcement`、`task`/`task_run`、`schedule`/`schedule_run`、`dict_type`、`audit_log`、`visibility`、`cancelled`（双写 l）、`client`
- 数据库相关描述：PostgreSQL only，表 `sand_<域>_*`，列小写蛇形，时间点 `*_time`
- 示例代码：类 PascalCase、函数/变量 camelCase、常量大写蛇形；函数/方法 ≤4 词、动词开头；不用 `Manager/Helper/Util` 等空泛后缀与自造缩写；布尔 `is/has/can/should` 开头
- 注释：只写代码看不出的原因/约束；禁流水账、横幅分隔线、表情、改动记录；新文件注释简体中文；`TODO(负责人或#编号): 原因`
- 完整规范见 `/Users/supdger/agent-work/grokbot/devin-queue/命名与注释规范.md`（v2）

## 提交规矩

- 只 `git add` 具体文件，禁 `git add -A`/`git add .`；不提交密钥、token、本机路径、临时产物
- 不 force push、不 `reset --hard`、不 `--no-verify`；不合并 PR、不打 tag

## 测试和检查命令

- 纯文档/索引仓，无测试与构建命令；改动 `docs/` 后人工核对链接与事实

## 许可证

根 `LICENSE` 为 MIT（版权 青岛沙盒创意网络科技有限公司）。各组件许可证以各自仓库 `LICENSE` 为准：Sand Core、Sand Package、SandAI、SandIAM 为 MIT；SandWorkflow 按目录划分（后端 MIT、前端 AGPL-3.0），详见其 `NOTICE`。
