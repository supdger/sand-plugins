# 外部目录同步驱动（后端）

SandIAM 内置的 Microsoft Graph、Google Workspace Directory 与 Keycloak 驱动均是**入站只读**同步。连接配置由既有 SyncConnector 敏感接口加密保存；管理员填写的 access token 不会出现在 URL、审计、普通日志或读取响应中。配置后先执行既有“测试连接”，再运行同步；先使用小范围测试应用，确认新建、停用与缺失保护结果后才扩大范围。

## 填什么、来源和结果

| 驱动代码 | 配置项 | 来源 | 同步结果 |
| --- | --- | --- | --- |
| `microsoft_graph` | `tenant_id`、`access_token`，可选但只能是固定的 `https://graph.microsoft.com/v1.0` | Entra tenant 与具备用户读取授权的 Graph access token | 使用 users delta；保存官方 delta link 作为下次增量游标。 |
| `google_workspace` | `domain`、`access_token`，可选但只能是固定的 `https://admin.googleapis.com` | Google Workspace 域名与 Directory API access token | 使用 users.list 的 `pageToken`；完成一轮后下一次重新做全量快照。 |
| `keycloak` | `base_url`、`realm`、`access_token` | 自建 Keycloak 的公网 HTTPS 地址、realm 与 Admin REST access token | 使用 users 的 `first/max` 分页；完成一轮后下一次重新做全量快照。 |

统一映射：上游稳定用户 ID 为 `external_subject/source_id`，email、显示名、enabled/suspended 状态写入受加密保护的快照。Graph 的 `@removed` 与 Google 的 `deletionTime` 映射为删除；Keycloak users 列表没有删除记录，依赖完整快照与既有缺失保护处理。

组成员不在本轮请求范围：Graph、Google、Keycloak 都需要额外的组/成员端点和独立权限，因此驱动不会猜测或生成 `group_codes`。若管理员将 group authority 设为 source，必须先使用支持组成员事实的专项驱动。

## 安全和常见错误

所有外呼强制 HTTPS、公开 DNS 解析与地址钉扎，禁用 redirect 和 proxy；Graph continuation 必须仍是 `graph.microsoft.com/v1.0/users/delta`，跨域 next/delta link 返回 `SAND_IAM_SYNC_CURSOR_INVALID`。每页最多 500 条，既有同步服务最多 100 页；429 仅在 `Retry-After` 为 0–5 秒时最多重试两次，其他情况返回 `SAND_IAM_SYNC_REMOTE_RATE_LIMITED`。重复 external subject、坏 cursor 或非 JSON 响应均 fail-closed。

官方来源：Microsoft [users delta](https://learn.microsoft.com/en-us/graph/api/user-delta?view=graph-rest-1.0)、Google [Directory users.list](https://developers.google.com/workspace/admin/directory/reference/rest/v1/users/list)、Keycloak [Admin REST API users](https://www.keycloak.org/docs-api/latest/rest-api/index.html)。本轮只有 fake transport 合同测试，未连接任何真实租户、Google Workspace、Keycloak 或宿主 HTTP。
