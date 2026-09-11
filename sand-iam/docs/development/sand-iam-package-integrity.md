# SandIAM 包完整性与发布来源门禁

`tools/check-package-integrity.php` 只读检查权威源码包，不访问宿主或数据库。它把“候选包内自洽”和“可发布来源”分成两道门，前者绝不等同于后者。

## 候选包内自洽

```bash
php sand-iam/tools/check-package-integrity.php
```

默认检查迁移与根/插件生命周期、Composer/SAML 类解析、后端/路由/配置、管理端载荷、SDK 文档、PostgreSQL 方言及版本一致性。输出的 `passed/total` 只说明当前工作树中的包内文件相互匹配。

这里的“版本一致性”只指可安装插件包的候选发行版本（当前为根与插件 `info.ini` 和运行配置中的 `0.7.0`）。管理 OpenAPI 目录的 `info.version=0.12.0-candidate` 是独立的接口契约版本，不与插件包版本比较，也不能据此推断已发布或已部署；它的值和口径由[管理端接口交接](sand-iam-management-api-v0.1.md)冻结。

工作树即使干净，默认输出仍是 `CANDIDATE`；它不证明来源、审核或签名，也不能作为发布结论。

需要交给人工或 CI 审核时，生成只读候选清单：

```bash
php sand-iam/tools/check-package-integrity.php --print-candidate-manifest > /tmp/sand-iam-candidate.json
```

候选清单的 schema 是 `sand-iam.candidate-manifest/v1`，含版本、迁移文件清单/数量、关键文件哈希和可复现包哈希。它的 `kind` 固定为 `candidate-review-only`，不能直接作为可信发布清单。

### 失败升级恢复描述器

候选包根与其 `plugin/sand-iam/` 镜像各自携带完全相同的
`recovery/failed-upgrade.v2.json`。这是声明性恢复前置条件，不是宿主
verifier、数据库修复脚本或升级执行器。当前唯一声明绑定
`SandIAM 0.6.0 → 0.7.0`，并把
`sandpackage.failed-upgrade-recovery-profile/v2` 的 `prefix_033_034`
profile 直接放入描述器；其 `update_lifecycle` 只接受根 `update.sql` 的
SHA-256。profile 只包含宿主冻结的关系、列、约束、索引、菜单和账本断言词汇，
不携带可执行查询、PHP、shell 或本机绝对路径。

生成顺序固定为先算排除描述器本身的规范化载荷清单，再写 canonical JSON：

```bash
php sand-iam/tools/build-failed-upgrade-recovery-descriptor.php
php sand-iam/tools/check-package-integrity.php --print-normalized-recovery-payload-manifest
```

规范化清单包含全部受控候选文件（包括生命周期 SQL、后端、前端和元数据），只排除根与插件镜像的该描述器及其可选 `.sha256` 伴随文件。因而描述器中的 `candidate_payload.digest` 不会把自身纳入输入，也不存在自引用哈希循环。完整性门禁拒绝非递归 canonical JSON、重复或未知键、profile 变化、根/插件差异、载荷摘要不匹配，以及 `update.sql` 摘要不匹配；它不会执行候选 SQL、PHP、数据库或宿主 verifier。

## 发布来源

发布检查必须同时给出位于 `sand-iam/` 包根**之外**、由受控 CI 或人工发布流程保存的可信来源清单：

```bash
php sand-iam/tools/check-package-integrity.php \
  --release \
  --trusted-manifest=/controlled/release-attestations/sand-iam-0.7.0.json \
  --trusted-public-key=/controlled/release-keys/sand-iam-ed25519.pub
```

该清单必须使用 `sand-iam.release-provenance/v1`，`kind` 为 `external-reviewed-release`，并精确匹配候选包的：

- `version`、`migration_file_count`、`migrations`；
- `key_file_hashes` 和 `package_sha256`；
- `provenance.source`、`reference`、`approved_by`、`approved_at`；
- `provenance.signature.algorithm=ed25519` 与 `provenance.signature.value`。

清单的 detached signature 必须对 canonical unsigned JSON payload 验证：递归按字典序排列对象键、保留数组次序，使用 `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION` 编码，并从 payload 中移除 `provenance.signature` 后作 Ed25519 签名。公钥必须是包根外的独立 regular file，严格 base64 解码后正好 32 字节；签名严格 base64 解码后正好 64 字节。检查器使用 PHP sodium 的 `sodium_crypto_sign_verify_detached` 验签；环境未提供 sodium 时发布检查关闭失败。

检查器拒绝缺失清单或公钥、包内路径、任何路径链中的符号链接、候选 schema、篡改哈希、非 Ed25519 签名、伪签名或缺少来源字段。公钥只从显式 `--trusted-public-key` 读取，绝不接受 manifest 自带的任意公钥。发布载荷枚举同样拒绝任何会被纳入发布包的文件/目录符号链接，并且不会将链接目标纳入哈希。它不生成、不移动、也不把候选清单升级为可信清单。

`0.7.0` 另有迁移不可变门禁：根与包内 `021_admin_permission_catalog.pgsql` 必须等于已发布 0.6.0 的 SHA-256；035 账本的已知文件名、修订号、哈希和包版本必须完整一致，036 的所有自引用校验值必须等于将该值规范化为 `__SELF_SHA256__` 后的 SHA-256，037 必须登记初始化草稿/修订表并保持前三项新权限只入目录、不自动授予角色。fresh install 完整带入 `001–037`，而 `0.6.0 → 0.7.0` 载荷只能包含 `033–037`。035 不把空账本当成旧版事实，必须先核验 0.6.0/033 的表、列、约束和索引指纹；其中组织级身份源的 `application_id` 明确允许为空，但只有精确存在且规范化定义一致的 `ck_sand_iam_identity_provider_scope` 才可证明应用级非空、组织级为空的作用域边界。任何未知文件名、多余账本行、文件名、哈希、版本、列可空性或上述约束定义冲突都会拒绝继续。SandPackage 的通用升级选择与已安装状态收养不在本插件范围，必须在宿主侧先独立修复和验收。

完整性测试可在 `/private/tmp` 临时生成 Ed25519 密钥对来验证验签逻辑；该夹具不是、也绝不能被当作本项目的可信发布公钥或发布证据。

## 当前边界

当前源码工作树是候选状态，且没有被提供的包外可信来源清单或可信 Ed25519 公钥；因此 `--release` 必须失败。即使候选包完整性全绿，也不能称为已发布、可上线或已完成发布验收。

## 本地 review-only 候选包

权威源码中的 `tools/build-review-candidate.php` 是本地候选包入口。它先将
完整受控 payload 冻结到 artifact 内的不可变临时快照，再从该快照构建；不从
时间戳 artifact 私有脚本反向构建。`tools/package-payload-policy.php` 是 builder、
checker 与 descriptor 共用的唯一载荷清单：包含完整插件 backend（含已提交 vendor）、
根/插件 lifecycle 与元数据镜像、管理端运行源码、账户门户及 public assets、公共 SDK、
用户说明和公开协议示例；排除开发文档/任务板、测试、artifact、缓存、秘密、builder/
checker 与其他开发工具。根/插件 recovery descriptor 是快照 materialize 完成后唯一
生成的两个文件，因而绑定的是最终 archive payload 而非 authority tree，也不会产生
自引用。`getting-started` 的 `.test` 文件及仅由行为/viewport mock harness 互相引用的
七个 `getting-started.*` helper 同样按测试材料排除；实际 `index.vue`、
`WizardStepForm.vue`、`wizardState.ts` 仍在管理端运行载荷中。

TypeScript SDK 的 `sdk/typescript/dist/**` 是唯一可进入候选包的 `dist` 路径。检查器
静态读取 SDK `package.json` 的 exports/types，递归解析其 ESM 与 declaration 相对导入，
要求每个目标都位于该白名单、存在于受控候选载荷且不存在路径逃逸；任何其他 `dist` 载荷
均会拒绝。SDK 构建物必须由锁定的本地 TypeScript 工具链从 `src/` 重建，禁止手改。

```bash
php sand-iam/tools/build-review-candidate.php
```

该命令仅生成 `candidate/dirty-not-release` 的本地审核物证，绝不注册、上传、同步、
安装或部署。每次候选及同快照的重复构建均须由 artifact 自带 manifest、provenance、
validation/rebuild 证据复核；详见 [`tools/README.md`](../../tools/README.md)。
