# OpenAPI 接口目录导入

SandIAM 管理台的 **路由清单 → 导入 OpenAPI 接口目录** 接受 OpenAPI 3.0/3.1
JSON 导入包。导入包把文档中的每个 HTTP operation 映射到当前应用中已经存在的
业务资源和已发布业务动作。系统保存接口目录和路由绑定，不保存原始 OpenAPI 文档。

先下载或复制[完整 JSON 示例](examples/openapi-import.json)，再替换
`organization_code`、`application_code`、`environment_code`、`resource_code` 和
`action`。每个 GET、POST、PUT、PATCH、DELETE operation 都必须：

- 有不超过 128 字符的 `summary`；
- 通过 `x-sand-iam.riskLevel` 标注 `low`、`medium`、`high` 或 `critical`；
- 有且只有一条 `mappings` 记录。

`mappings` 字段：

| 字段 | 必填 | 含义 |
| --- | --- | --- |
| `operation_key` | 是 | 大写 HTTP 方法、一个空格和 `paths` 中的原始路径模板，例如 `GET /work-items/{id}`。 |
| `api_code` | 是 | 接口目录代码，小写字母开头，可含数字、`.`、`_`、`:`、`-`，2–96 字符。 |
| `api_version` | 否 | 接口版本，默认 `v1`，1–32 字符。 |
| `resource_code` | 是 | 当前应用中已存在的业务资源代码。 |
| `action` | 是 | 当前应用中已发布的业务动作代码。 |
| `audience` | 是 | 访问令牌受众，1–128 字符。 |
| `required_scope` | 否 | 调用所需 scope；不需要时传空字符串或省略。 |

先调用
`POST /app/sand-iam/admin/developer/openapi-import/preview`，请求体为
`{"import": <完整导入包>}`。预检不写数据；只有响应的 `can_apply=true` 才能确认。
应用时向 `/openapi-import/apply` 传同一导入包、预检返回的 `preview_hash` 和
`apply: true`。导入包有任何变化都必须重新预检。

`disable_missing=true` 会停用同一应用中来源为 `openapi`、但已不在本次完整文档
中的路由，不会接管手工登记或路由扫描的绑定。第一次导入或分模块维护文档时保持
`false`。

PHP：

```php
$preview = $management->openApiImportPreview($input, 'openapi-preview-001');
$management->openApiImportApply(new SandIamOpenApiImportOperation(
    $input,
    $preview['preview_hash'],
    'openapi-apply-001',
));
```

TypeScript：

```ts
const preview = await management.openApiImportPreview(input, 'openapi-preview-001')
await management.openApiImportApply({
  input,
  previewHash: String(preview.preview_hash),
  requestId: 'openapi-apply-001'
})
```

Dart：

```dart
final preview = await management.openApiImportPreview(
  input,
  requestId: 'openapi-preview-001',
);
await management.openApiImportApply(SandIamOpenApiImportOperation(
  input: input,
  previewHash: preview['preview_hash']! as String,
  requestId: 'openapi-apply-001',
));
```

导入包不得包含密码、令牌、客户端密钥或真实凭证。预检返回未映射 operation、
资源不存在、动作未发布、归属冲突或 stale 错误时，按错误详情修正后重新预检。
