# 外部身份源预设（后端）

预设目录只给管理员提供已核对的协议元数据和**不保存的配置草稿**。它不是一键开通：不会创建身份源、不会挂载应用、不会自动启用，也不接收、保存或回显 `client_secret`、应用密钥、token 或私钥。

管理 API 均复用 `sand_iam:identity_provider:read`：

- `GET /app/sand-iam/admin/identity-provider-preset/index`：列出预设、协议、适用地区、核对日期和官方来源。
- `GET /app/sand-iam/admin/identity-provider-preset/read?code=...`：查看一个预设。
- `POST /app/sand-iam/admin/identity-provider-preset/draft`：仅生成并校验草稿；提交 `code`、`client_id`、`redirect_uri`、`handoff_return_uris`，Microsoft Entra ID 还需 `tenant_id`。返回值带 `draft_only=true`、`save_performed=false` 和下一步说明。

草稿接口拒绝任何名称含 `secret`、`password`、`private_key` 或 `token` 的输入。最终配置必须由已有的联合身份源敏感配置接口完成，继续沿用 FederationService 的 HTTPS、公开 DNS/SSRF、防重定向及 TLS 校验。

## 当前状态与配置来源

| 预设 | 协议 | 状态 | 管理员需要从哪里取得值 |
| --- | --- | --- | --- |
| GitHub OAuth 应用 | OAuth 2.0 | `compatible` | GitHub OAuth App 的 client ID、client secret、回调地址；草稿使用官方 authorize、token 与 `/user` 端点。官方：[OAuth web flow](https://docs.github.com/en/apps/oauth-apps/building-oauth-apps/authorizing-oauth-apps)。 |
| Google OpenID Connect 登录 | OIDC | `compatible` | Google Cloud OAuth client 的 client ID、client secret、回调地址；草稿固定使用官方 discovery，而非猜测端点。官方：[OIDC reference](https://developers.google.com/identity/openid-connect/reference)。 |
| Microsoft Entra ID 登录 | OIDC | `compatible` | Entra App registration 的 tenant ID、client ID、client secret、回调地址；草稿按 tenant ID 生成 v2 discovery URL。Azure 中国或独立云必须人工核对各自端点。官方：[OIDC protocol](https://learn.microsoft.com/en-us/entra/identity-platform/v2-protocols-oidc)。 |
| 飞书用户授权 | OAuth 类授权 | `manual_required` | 当前仅核对到官方用户信息资料，未确认可直接套入 SandIAM 通用 OAuth2/OIDC 的完整端点和声明；不得猜测 URL。官方：[获取用户信息](https://open.feishu.cn/document/server-docs/authentication-management/login-state-management/get)。 |
| 钉钉登录 | OAuth 类登录 | `manual_required` | 官方教程确认登录场景，但本版本未确认可直接套入的端点/声明；必须专项适配后才可启用。官方：[钉钉教程](https://open.dingtalk.com/tutorial/)。 |
| 企业微信网页授权 | OAuth 类授权 | `manual_required` | 当前未从官方页面确认与现有通用 OAuth2/OIDC 完全兼容的端点和声明；不得手填猜测 URL。官方：[企业微信开发文档](https://developer.work.weixin.qq.com/document/)。 |
| 微信开放平台网站登录 | OAuth 类登录 | `manual_required` | 当前未从官方页面确认与现有通用 OAuth2/OIDC 完全兼容的端点和声明；不得手填猜测 URL。官方：[网站应用微信登录](https://developers.weixin.qq.com/doc/oplatform/Website_App/WeChat_Login/Wechat_Login.html)。 |

`manual_required` 返回 `SAND_IAM_IDP_PRESET_MANUAL_REQUIRED`；未来如确认某厂商协议与现有 OAuth/OIDC/SAML 均不兼容，目录会明确标为 `unsupported` 并返回 `SAND_IAM_IDP_PRESET_UNSUPPORTED`。两者都不能生成通用草稿。草稿字段不满足 HTTPS、回调白名单或 tenant 格式时返回 `SAND_IAM_IDP_PRESET_DRAFT_INVALID`；误把密钥提交给草稿接口返回 `SAND_IAM_IDP_PRESET_SECRET_NOT_ALLOWED`。
