# SandIAM 应用登录体验与消息服务契约（v0.1 候选）

> IAM-T09 后端候选契约。`011` 与跨组织挂载约束 `025` 已进入根生命周期并通过 SandPackage 安装；消息服务选择、加密配置、投递/Captcha、停用关闭失败及数据库隔离已通过 PostgreSQL 集成，但真实 HTTP、浏览器和供应商联调尚未完成。当前不得标记为可部署。商业许可和业务消息中心不属于本契约。

## 1. 一个使用者怎样完成配置

管理员按以下顺序操作，不需要理解数据库表：

1. 在“登录外观”选择接入应用，填写应用显示名称、Logo、品牌主色、语言、协议链接、登录方式和开放注册方式；
2. 客户主体管理员在“消息服务”新建邮件、短信、人机验证或站外通知服务，选择部署方已安装的驱动；
3. 在不记录请求正文的秘密配置接口填写供应商密钥。保存后只显示“已配置”和配置版本，不回显密文或原值；
4. 为应用选择要使用的消息服务、使用场景、供应商模板代码和优先级；
5. 执行测试发送或挑战校验，再启用认证策略中的“登录前人机验证”；
6. 应用前端读取公开登录体验接口，渲染自己的登录、注册和安全门户。SandAdmin 只负责配置，不承载终端用户登录页。

应用管理员可以查看同一客户主体下可供本应用选择的消息服务并管理应用挂载，但不能读取或修改组织级供应商密钥。客户主体管理员和平台管理员负责供应商实例与秘密。

## 2. 登录外观

每个接入应用最多一条配置：

| 接口字段 | 管理端中文 | 用户为什么要填 | 默认列表是否显示 |
| --- | --- | --- | --- |
| `application_id` | 接入应用 | 决定配置属于哪个产品 | 显示应用名称，不显示 ID |
| `brand_name` | 应用显示名称 | 登录页标题 | 是 |
| `logo_url` | Logo 地址 | 产品品牌图标，仅 HTTPS | 缩略图 |
| `primary_color` | 品牌主色 | 按钮和重点状态，格式 `#RRGGBB` | 色块 |
| `theme_mode` | 默认主题 | 跟随系统、浅色或深色 | 是 |
| `default_locale` | 默认语言 | 第一版默认 `zh-CN` | 否 |
| `terms_url` / `privacy_url` | 服务协议 / 隐私政策 | 注册前可查看的正式文本，仅 HTTPS | 否 |
| `registration_mode` | 注册方式 | 开放注册、邀请注册、关闭注册 | 是 |
| `login_methods` | 登录方式及顺序 | 密码、通行密钥或已挂载外部身份源 | 摘要 |
| `registration_fields` | 注册时填写 | 用户名、显示名称、邮箱、手机号 | 摘要 |
| `status` | 状态 | 是否对外提供该配置 | 是 |

`password` 与 `passkey` 是本地方式；联合登录使用 `oidc:<身份源公开代码>`、`oauth2:<身份源公开代码>` 或 `saml:<身份源公开代码>`。保存时必须证明该身份源已启用并挂载到同一应用。配置不存在时保持旧版认证兼容；配置启用后，密码登录未列入编排就必须拒绝，不能只在页面隐藏按钮。

公开接口：

```http
GET /api/sand-iam/v1/experience?organization_code=<客户主体代码>&application_code=<接入应用代码>
```

响应只有品牌、方式和公开链接；不返回数据库 ID、内部外键、供应商驱动、模板、密文或身份源内部配置。启用生命周期前返回 `503 SAND_IAM_APPLICATION_EXPERIENCE_UNAVAILABLE`，防止查询未安装表。

## 3. 消息服务

服务类型固定为：`email` 邮件、`sms` 短信、`captcha` 人机验证、`notification` 站外安全通知。驱动代码来自数据库，但驱动类映射只允许由部署环境 `SAND_IAM_MESSAGE_DRIVERS` 配置，例如：

```json
{"aliyun-sms":"App\\Iam\\AliyunSmsDriver"}
```

数据库写入者因此不能指定任意 PHP 类。发送驱动契约：

```php
public static function send(
    string $destination,
    string $code,
    array $context,
    array $providerConfig,
): void;
```

Captcha 驱动契约：

```php
public static function verify(
    string $token,
    array $context,
    array $providerConfig,
): bool;
```

驱动不得记录接收地址、验证码、挑战令牌或完整供应商响应。异常向上抛出，认证流程关闭失败；禁止假发送、固定验证码和 Captcha 故障时放行。

`sendCode()`/`sendMessage()` 返回 `false` 表示数据库消息服务未启用或没有匹配挂载；返回 `true` 表示选定驱动的 `send()` 正常返回，不代表收件人已收到。驱动应检查供应商响应并对拒绝或失败抛出异常；`MessageProviderService` 本身不进行自动重试或切换已选服务。超时可能发生在供应商已接收之后，重新发送前应核对供应商记录，避免重复通知。

服务传给驱动的保留上下文字段以明确参数及挂载配置为准，覆盖调用者 context 中的同名值：发送使用 `application_id/provider_type/template_code`，验证码校验使用 `application_id/action`，测试固定 `test=true`（发送测试还固定 `provider_type`）。其他扩展字段保留。`context.purpose` 仍用于选择模板：先取该目的模板，再回退到挂载用途模板，均不存在时使用空字符串。

### 独立门户 Captcha 接续契约

已提供具体 `plugin\SandIam\app\integration\Captcha\TurnstileClient`，保留上述
`verify()` 契约；配置包含 `site_key`、`secret_key` 和精确 `hostnames` 列表。
它必须通过既有受信任驱动映射及加密配置流程显式安装配置，不会自动启用。
本批不执行供应商配置、密钥写入或网络请求。

支持门户的驱动另提供 `publicChallenge(array $context, array $providerConfig): array`。
上下文为可信 `application_id` 与 `action=login|register`；`MessageProviderService::publicChallenge()`
使用与验证相同的挂载选择，重新白名单投影 `kind`、`site_key`、`action`、
`application_binding` 四字段，不透传供应商配置。Turnstile 的绑定值通过供应商 `cData`
传回，并在服务端独立校验；同时检查 hostname、action 和验证成功结果。

门户组件仅负责挑战生命周期，令牌只在内存中保存并单次消费；切换应用、销毁、
过期或失败后旧回调不可恢复令牌。公开配置端点及前端配置读取已实现，契约见
[开发入口](sand-iam-development-entry.md)；登录/注册页面接线尚未完成。
不能将客户端与组件的离线通过写成真实供应商或用户登录闭环通过。
供应商协议依据：[Turnstile 服务端验证](https://developers.cloudflare.com/turnstile/get-started/server-side-validation/)。

管理 API：

| 操作 | API | 说明 |
| --- | --- | --- |
| 列表/详情 | `GET /app/sand-iam/admin/message-provider/index|read` | DTO 只含名称、类型、驱动代码、配置版本和“是否已配置” |
| 创建/修改/停用 | `POST .../save|update|disable` | 停用服务同时停用所有应用挂载 |
| 写入/轮换秘密 | `POST .../configure` | 请求不经过 `SystemLog`；整份配置加密覆盖并增加版本 |
| 测试 | `POST .../test` | 请求不经过 `SystemLog`；审计不含接收地址、验证码或 token |
| 应用可选项 | `GET .../options?application_id=...` | 供应用管理员按名称选择，不手填 ID |
| 应用使用规则 | `GET .../mounts`、`POST .../mount|unmount` | 用途、模板代码、优先级和状态 |

邮件/短信验证码仍由 `HumanAuthService` 生成 8 位随机码，只保存带部署 pepper 的哈希、目的、接收地址指纹、过期时间和尝试次数。消息服务只负责投递。若数据库消息服务功能未启用或应用没有挂载，现有部署级 sender 继续作为迁移期兼容路径；一旦匹配到数据库服务，投递失败不得回退到另一条未审计路径。

## 4. 秘密与轮换

- 供应商配置使用独立 32 字节 secretbox key，不复用密码 pepper、MFA、联合身份源或 Webhook key；
- 新密文前缀保存 key version；旧版本仅用于解密历史配置；
- `configure` 是整份配置轮换，原值和密文都不出现在列表、详情、响应、日志或审计；
- 启用变量：`SAND_IAM_APPLICATION_EXPERIENCE_ENABLED=1`、`SAND_IAM_MESSAGE_PROVIDER_ENABLED=1`，只能在 `011` 完成生命周期和 PostgreSQL 验收后设置；
- 密钥变量：`SAND_IAM_MESSAGE_ENCRYPTION_KEY`、`..._VERSION`、`..._KEYS`。

## 5. 错误与审计

稳定错误包括：体验未安装/未找到、登录方式关闭、消息服务配置无效/不可用、Captcha 必填/无效/不可用、跨客户主体挂载拒绝。管理端的创建、配置、测试、停用、挂载和解绑均写审计；认证侧验证码发送结果和 Captcha 判断写审计，但不写敏感材料。

## 6. 当前证据和未完成项

已完成：模型、控制器、公开 DTO、认证接线、版本化配置加密、部署驱动注册、`011` 与 `025` 生命周期、PHP 语法检查、非 PostgreSQL 契约、v1→v2 密钥轮换，以及服务选择、投递、Captcha、停用关闭失败、跨组织挂载拒绝的 PostgreSQL 集成。

未完成：真实宿主 HTTP、真实邮件/短信/Captcha 供应商、终端用户登录/注册/恢复/安全门户、管理端页面和浏览器体验。`notification` 当前只有实例管理和测试；事务化安全事件生产者归 IAM-T12，不能在本任务中宣称自动通知已形成业务闭环。

## 验收字段与错误码对账

| 原子 ID | 字段 | 错误码/恢复 |
| --- | --- | --- |
| R08-T09-01 | `application_id`、`brand_name`、`login_methods` | `SAND_IAM_APPLICATION_EXPERIENCE_UNAVAILABLE`：保持不可用状态并稍后重试，不暴露内部配置。 |
| R08-T09-02 | `provider_type`、`driver`、`config_version` | `SAND_IAM_MESSAGE_PROVIDER_CONFIG_INVALID`：拒绝保存或测试无效配置，秘密不回显。 |
| R08-T09-03 | `login_methods`、`session_id`、`mfa_state` | `SAND_IAM_AUTHENTICATION_FAILED`：保持可重试输入，不泄露认证细节。 |
