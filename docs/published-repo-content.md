# 发布仓内容策略

各插件的 GitHub 公开仓（`supdger/sand-core`、`supdger/sand-package`、`supdger/sand-iam`、`supdger/sand-workflow`、`supdger/sand-ai`）面向**最终用户**，不面向开发者/协作者。仓内只保留两类内容：

1. **仓库需要的文档与元数据**：`README.md`、`CHANGELOG.md`、`LICENSE`/`NOTICE`/`THIRD_PARTY_NOTICES.md`、`SECURITY.md`、`SBOM.cdx.json`、`composer.json`、`info.ini`、`config.json`、生命周期 SQL、发布契约 JSON、`.gitignore`。
2. **用户需要的必要文件**：运行载荷（`plugin/`、`server/`、`sandadmin-artd` 插件视图、`portal/`、`sdk/`）、用户文档（`docs/user-guide`）、可复制示例（`examples/`）、用户验签/完整性工具（`tools/` 中的发布与校验工具）。

**一律不带**：测试与夹具（`tests/`、`__tests__`、`fixtures`、`*_test.*`、`*.test.*`、`*.spec.*`）、mock 与前端预览脚手架（`*-mock.*`、viewport/mount harness）、CI 与 IDE/Agent 设施（`.github/`、`.vscode/`、`.husky/`、`.codex/`、`.cursor/`、`.claude/`、commitlint/eslint/prettier/stylelint 配置、`phpunit.xml`）、开发文档（`docs/development`、`docs/product`、`AGENTS.md`、`CLAUDE.md`、openspec）、真实 `.env`（`.env.*example` 除外）、未声明为消费面的 `dist/` 产物。

## 机械检查

重新发布任一插件仓前，对导出暂存树运行：

```sh
/usr/bin/python3 scripts/check-published-repo-content.py --plugin <repo> --root <export-dir>
```

失败即不得推送。回归测试：`/usr/bin/python3 scripts/check_published_repo_content_test.py`。

## 目录约定

- 顶层 `app/`、`config/` 是 SandPackage 受控宿主载荷保留前缀：安装器 `HostPayloadManifest.allowedPath` 仅允许 `app/**.php` 等受控 PHP 载荷占用（如 SandAI 安装到宿主 `app/**` 的载荷）。随包发行的非 PHP 源码（TS/JS 前端、文档源码等）放在顶层 `app/`/`config/` 下会导致整包校验拒绝、无法安装。
- 因此插件对外用户面源码（如 SandIAM 终端门户 SPA 源码）一律用 `portal/` 顶层目录；产品名仍可叫"门户/portal"。SandIAM 0.8.5 曾误用 `app/` 发行门户 TS 源码导致包布局不合规，0.8.6 回退为 `portal/`。
- `plugin/<app>/`（后端+载荷）、`sandadmin-artd/`（管理端源码）、`sdk/`、`examples/`、`release/`（发布机器收编位）、`docs/user-guide` 为其余标准顶层。
- `plugin/<app>/docs/` 是随包契约文档，属用户面；只有仓根级 `docs/development|product|internal` 才算开发内容。

## 已记录的判定

- `release/` 是发布机器收编位（2026-11-01 收编落地）：sand-iam 的 `tools/` 与 `recovery/`、sandworkflow 的 `tools/` 已迁入 `release/tools|`recovery`。`migrations/` 与 `lifecycle/` 属安装 ZIP 载荷路径（SandPackage 升级预检与安装器按包根消费），不属发布机器，保持包根不动。公开仓发布 `release/` 中的用户验签/完整性子集，不发布构建与验收机器。
- `sand-iam/CONTRIBUTING.md` 保留：仓内 `check-package-integrity` 与 `check-release-payload` 将其作为发布治理机制（DCO/CLA 必选其一）的硬性输入。若要移除须先改发布契约，不是删文件能解决。
- `plugin/sand-iam/vendor/` 上游 dev 残留已于 2026-11-01 裁剪：移除 casbin/php-saml/xmlseclibs 的 3 个 `phpunit.xml` 与 3 个 `.github/`（共 8 文件），`release-build-contract.json` 摘要已按裁剪后树重新生成（389→381 文件）。后续重灌依赖按同一口径裁剪并再生摘要。
- `sand-iam/docs/development|product` 为协作者单一权威正文，不进公开仓与安装包（与 gate 一致）；公开仓曾短暂发过的薄契约子集已随清理移除，不恢复随包副本，杜绝薄索引与正文漂移。
- `sandworkflow/tools/` 三脚本裁为维护者工具（2026-11-01）：`check-sandadmin-host.sh` 含开发机硬编码路径、`convert-install-sql.php` 为一次性 MySQL→PostgreSQL 转换器、`check-package-integrity.sh` 校验源码根与载荷一致性而非签名验签；三者均不进公开仓，公开仓 Wiki "随维护者源码树提供" 口径保持不变。
- `examples/` 内的测试保留：示例是给用户复制运行的接入样板，自带测试即用户验证手段。
- `sdk/typescript/dist/` 保留：包以原生 ESM 经 git 路径消费，`package.json` 指向 dist，属消费面产物；其余 `dist/` 一律视为开发产物。
- 开发母仓 `sand-plugins` 保留全部测试/文档/工具；发布仓是其白名单子集，回潮即违规。

2026-11-01 首次执行：sand-package 164→89、sand-core 565→547、sand-iam 1112→1066 文件；sand-workflow 原本即合规。
