# SandIAM 包完整性与发布来源门禁

`tools/check-package-integrity.php` 只读检查权威源码包，不访问宿主或数据库。它把“候选包内自洽”和“可发布来源”分成两道门，前者绝不等同于后者。

## 候选包内自洽

```bash
php sand-iam/tools/check-package-integrity.php
```

默认检查迁移与根/插件生命周期、Composer/SAML 类解析、后端/路由/配置、管理端载荷、SDK 文档、PostgreSQL 方言及版本一致性。输出的 `passed/total` 只说明当前工作树中的包内文件相互匹配。

这里的“版本一致性”只指可安装插件包的候选发行版本（当前为根与插件 `info.ini`、运行配置、门户及随包 Composer 根包元数据中的 `0.7.2`）。管理 OpenAPI 目录的 `info.version=0.13.0-candidate` 是独立的接口契约版本，不与插件包版本比较，也不能据此推断已发布或已部署；它的值和口径由[管理端接口交接](sand-iam-management-api-v0.1.md)冻结。

工作树即使干净，默认输出仍是 `CANDIDATE`；它不证明来源、审核或签名，也不能作为发布结论。

需要交给人工或 CI 审核时，生成只读候选清单：

```bash
php sand-iam/tools/check-package-integrity.php --print-candidate-manifest > /tmp/sand-iam-candidate.json
```

候选清单的 schema 是 `sand-iam.candidate-manifest/v1`，含版本、迁移文件清单/数量、关键文件哈希和可复现包哈希。它的 `kind` 固定为 `candidate-review-only`，不能直接作为可信发布清单。

### 历史失败升级恢复描述器

`recovery/failed-upgrade.v2.json` 与其插件镜像仅保留为 0.7.0 的历史证据：它绑定
`0.6.0 → 0.7.0` 的 `ledger_absent` 恢复场景。0.7.2 的正常载荷策略显式排除这两个文件，
也禁止构建工具依据当前 0.7.2 元数据重写它们。SandPackage 因而可以将 0.7.2 作为正常升级包
处理，而不 opt-in 历史恢复；这不构成对 0.6.0 直升或任何宿主恢复动作的声明。

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

`0.7.2` 保持既有 `001–038` 均不可变，并新增自校验迁移 `039`、`040`：根与包内 `021_admin_permission_catalog.pgsql` 必须等于已发布 0.6.0 的 SHA-256；035 账本的已知文件名、修订号、哈希和包版本必须完整一致，036–040 的所有自引用校验值必须等于将该值规范化为 `__SELF_SHA256__` 后的 SHA-256。fresh install 完整带入 `001–040`；正常 `0.7.1 → 0.7.2` 先精确核验 001–038 的 39 条账本身份、账本结构、服务授权数据分级列和 038 保留索引，再依次内联 039、040。成功时账本必须为 41 行且最大修订号为 40；未知、多余、缺失或冲突账本行均拒绝继续。SandPackage 的通用升级选择与已安装状态收养不在本插件范围，必须在宿主侧先独立修复和验收。

完整性测试可在 `/private/tmp` 临时生成 Ed25519 密钥对来验证验签逻辑；该夹具不是、也绝不能被当作本项目的可信发布公钥或发布证据。

## 当前边界

当前源码工作树是候选状态，且没有被提供的包外可信来源清单或可信 Ed25519 公钥；因此 `--release` 必须失败。即使候选包完整性全绿，也不能称为已发布、可上线或已完成发布验收。

## 最终 ZIP 的包外签名

源码级来源清单通过后，最终 ZIP 还必须单独绑定。`build-review-candidate.php --release-unsigned`
只接受已提交且 `sand-iam/` 子树干净、已有获批 `LICENSE`、SBOM 未漂移、发布卫生和包完整性
全部通过的权威源码，输出 `release-candidate-unsigned` / `release/unsigned` manifest。正式构建从
当前 clean HEAD（可显式重复给出该 commit）以 Git blob materialize 两个独立 source stage；工作区
中的 ignored 或未跟踪 `vendor`、`dist` 和其他文件不被读取。每个 stage 的每个公开 payload 文件都
必须等于该 commit 的 Git blob，两个 stage 的 snapshot、条目映射和 ZIP 摘要都必须一致。默认 builder
仍固定输出 `candidate-review-only` / `candidate/dirty-not-release`，不能送签。

`release-build-contract.json` 是同一套权威材料的一部分：它固定实测 PHP、Composer、Node、pnpm、
TypeScript、ZIP/libzip 版本，Composer/pnpm lock 摘要、离线安装参数、TypeScript tarball integrity 与
58 个 vendor、4 个 SDK `dist` 文件的树摘要。完整性检查会拒绝 lock、工具链契约、vendor、dist 的
修改/删除，或任何可进入载荷但未被 Git 追踪的文件；依赖更新必须先在两个隔离目录按契约重建并逐字节比对，
然后一并审查生成物与契约。

独立审核者使用位于包根之外、权限为 `0600` 的 Ed25519 私钥运行
`tools/sign-release-bundle.php`。签名的 canonical JSON 同时绑定 ZIP 名称、SHA-256、字节数、
条目数、artifact manifest SHA-256、源码快照、`update.sql` 与审核来源。
`tools/verify-release-bundle.php` 使用包外可信公钥验签，并重新打开 ZIP 校验 manifest 中的每个
条目、路径安全、必需许可材料和测试排除。任何 dirty/review manifest、重打包、追加字节、
条目变化、manifest 变化或签名不匹配都关闭失败。

## 本地 review-only 候选包

权威源码中的 `tools/build-review-candidate.php` 是本地候选包入口。它先将
完整受控 payload 冻结到 artifact 内的不可变临时快照，再从该快照构建；不从
时间戳 artifact 私有脚本反向构建。`tools/package-payload-policy.php` 是 builder、
checker 共用的唯一载荷清单：包含完整插件 backend（含已提交 vendor）、
根/插件 lifecycle 与元数据镜像、管理端运行源码、账户门户及 public assets、公共 SDK、
用户说明和公开协议示例；排除开发文档/任务板、测试、artifact、缓存、秘密、builder/
checker 与其他开发工具，也排除根/插件的历史 recovery descriptor。`getting-started` 的 `.test` 文件及仅由行为/viewport mock harness 互相引用的
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
