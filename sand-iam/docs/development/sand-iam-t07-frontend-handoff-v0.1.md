# SandIAM T07 前端交接（v0.1 候选）

> 面向 Cursor。管理端源码边界仍为 `sand-iam/sandadmin-artd/src/views/plugin/sand-iam/**`。本契约允许先完成类型、页面和构建；`008–010` 已进入根生命周期，委托/Webhook 已有 PostgreSQL 集成，但未完成真实宿主 API/浏览器验收前不得写“可用”或“完成”。

## 1. 产品入口不是数据表目录

SandAdmin 管理面新增四条任务入口，不能把每张表平铺成一级菜单：

1. **管理范围**：客户主体管理员、应用管理员委派；
2. **接口与访问控制**：接口目录、路由绑定、策略入口；
3. **事件通知**：Webhook 配置、投递记录和失败重试；
4. **审计与导出**：查询、详情、导出和按请求标识排错。

应用用户的登录、安全和资料自助属于 SandIAM 运行平面，不放进 SandAdmin 左侧菜单。后续独立运行面使用 `sand-iam/portal/` 源码构建到插件 `public/account/`，由业务产品选择直接使用或基于前端 SDK 自建品牌页面；不得复用后台 `check_admin` 会话。

## 2. 页面 1：管理范围

### 应用管理员委派

- 接口前缀：`/app/sand-iam/admin/admin-application-grant`；
- 搜索后台管理员：`GET /admin-options?keywords=<至少2字>`；编辑回显可用 `?id=<用户ID>`；
- 列表默认列：后台管理员、接入应用、授权状态、操作；
- 不默认显示：数据库 ID、`admin_user_id`、`application_id`、创建人、更新时间；
- 表单：先搜索选择“后台管理员”，再选择“可管理的接入应用”；不要让人手填 ID；
- `admin_user_name` 是委派 DTO 的可读名称，`admin_user_id` 只用于提交和回显；
- 只有平台管理员或该客户主体管理员可增删委派；应用管理员不能把自己委派给别的应用。

稳定权限：

```text
sand_iam:admin_application_grant:index
sand_iam:admin_application_grant:read
sand_iam:admin_application_grant:save
sand_iam:admin_application_grant:update
sand_iam:admin_application_grant:disable
```

## 3. 页面 2：接口与访问控制

### 接口目录

- 接口前缀：`/app/sand-iam/admin/api-resource`；
- 默认列：接口名称、所属应用、对应业务资源、语义动作、数据操作、版本、风险等级、状态；
- 详情/表单字段：`application_id`、`resource_id`、`name`、`code`、`action`、`operation`、`api_version`、`audience`、`required_scope`、`risk_level`、`description`、`status`；
- 创建后不可修改：所属应用、业务资源、接口代码、语义动作、数据操作、接口版本；
- 操作中文：`list` 列表、`read` 详情、`create` 新增、`update` 修改、`delete` 删除、`export` 导出、`batch` 批量；
- 风险中文：`low` 低、`medium` 中、`high` 高、`critical` 关键；
- 页面必须解释：接口代码用于 SDK 调用，权限长期依据是“业务资源 + 语义动作”，不是 URL。

### 路由绑定

- 接口前缀：`/app/sand-iam/admin/api-route-binding`；
- 默认列：请求方法、路由模板、绑定接口、来源、状态、操作；
- 不展示 `route_fingerprint`；
- 来源中文：`manual` 手工登记、`openapi` OpenAPI 导入、`route_scan` 路由扫描；
- 创建后只允许停用/启用状态变更，变更方法、模板或目标接口应新建绑定；
- 页面明确提示：扫描只发现路由，不自动创建权限、策略或授权。

## 4. 页面 3：事件通知

### Webhook 配置

- 接口前缀：`/app/sand-iam/admin/webhook`；
- 默认列：通知名称、所属应用、接收地址、订阅事件摘要、密钥版本、状态、操作；
- 创建字段：`application_id`、`code`、`name`、`url`、`event_types[]`、`timeout_seconds`、`max_attempts`；
- `code` 创建后不可改；接收地址只能为 HTTPS；
- 创建和密钥轮换响应中的 `secret` 只显示一次，使用不可被普通关闭误丢失的安全弹窗；复制后要求用户确认“已保存”；前端状态和日志不得持久化密钥；
- 列表/详情不得期待 `encrypted_secret`，后端不会返回。

### 投递记录

- 列表接口：`GET /webhook/delivery/index?application_id=...`；
- 详情：`GET /webhook/delivery/read?id=...`；失败重试：`POST /webhook/delivery/retry`；
- 默认列：事件类型、事件标识、通知名称、结果、尝试次数、下次重试/完成时间、错误码、操作；
- 列表不显示 payload；详情可折叠显示格式化 payload，但不得提供修改；
- 状态中文：`1` 等待/已安排重试、`2` 投递中、`3` 已送达、`4` 最终失败；
- 仅等待和最终失败可手工重试。错误提示必须告诉用户是检查接收地址、签名密钥还是接收服务。

## 5. 页面 4：审计与导出

- 现有审计页继续使用 `/audit/index`、`/audit/read`；新增 `GET /audit/export`；
- 筛选：客户主体、接入应用、操作者类型、操作者、操作、资源类型、结果、请求标识、起止时间；
- 导出必须选择不超过 31 天的范围；超过 10,000 条时展示后端原始可恢复建议；
- 导出按钮权限：`sand_iam:audit:export`；有列表权限不等于有导出权限；
- 默认列：发生时间、接入应用、操作者、操作、资源、结果、请求标识；内部审计 ID 只在详情出现；
- 详情中的 `context` 使用中文键名映射和格式化查看器，不在列表平铺 JSON。

## 6. 独立应用用户运行面

第一版账户安全页包含：

- 个人资料：查看所属产品和显示名称，修改显示名称；
- 安全概况：密码、有效会话、TOTP、通行密钥、外部账号连接数量；
- 会话：识别当前会话，撤销其他会话；
- 登录方式：TOTP、恢复码、通行密钥、外部账号连接/解绑；
- 修改密码：明确提示成功后所有设备都需重新登录。

运行面使用 Bearer access token，只调用 `/api/sand-iam/v1/me/*` 与 `/api/sand-iam/v1/auth/*`。品牌名称取当前应用，不写死任何接入应用名称。无数据、令牌过期、无权、网络失败、功能未配置必须是不同状态。

## 7. Cursor 验收

1. TypeScript/Vue 不使用 `any`、`@ts-ignore` 或 `@ts-nocheck`；
2. 管理页面只展示上述有操作价值的列，机器 ID 和 JSON 不进入默认列表；
3. 管理员选择、应用/资源/接口选择均使用名称远程选择与编辑回显；
4. 一次性密钥不会进入 URL、localStorage、日志或错误上报；
5. 空、加载、失败、无权、已停用和冲突状态各自可辨；
6. 先分别记录类型检查、构建；生命周期完成后再记录真实 API 与两个视口浏览器证据；
7. 自助运行面必须证明未发送 `check_admin`，管理面必须证明未使用应用 access token。
