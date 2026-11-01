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

- `app/` 是全插件统一的"非后台应用面"顶层目录（SandAI 的 `app/`、SandIAM 的终端门户同一语义）。插件对外用户面一律用 `app/`，不得另造 `portal/` 等别名；产品名仍可叫"门户/portal"，但目录名固定 `app/`。
- `plugin/<app>/`（后端+载荷）、`sandadmin-artd/`（管理端源码）、`sdk/`、`examples/`、`release/`（发布机器收编位）、`docs/user-guide` 为其余标准顶层。
- `plugin/<app>/docs/` 是随包契约文档，属用户面；只有仓根级 `docs/development|product|internal` 才算开发内容。

## 已记录的判定

- `sand-iam/CONTRIBUTING.md` 保留：仓内 `check-package-integrity` 与 `check-release-payload` 将其作为发布治理机制（DCO/CLA 必选其一）的硬性输入。若要移除须先改发布契约，不是删文件能解决。
- `plugin/sand-iam/vendor/` 内部上游 dev 文件（casbin/php-saml/xmlseclibs 的 `phpunit.xml`、`.github/`）为已知残留：vendor 树被 `release-build-contract.json` 树摘要钉死，手工改动会断证明链；下次重灌依赖时以 `--no-dev`/裁剪清掉并重新生成契约摘要。
- `examples/` 内的测试保留：示例是给用户复制运行的接入样板，自带测试即用户验证手段。
- `sdk/typescript/dist/` 保留：包以原生 ESM 经 git 路径消费，`package.json` 指向 dist，属消费面产物；其余 `dist/` 一律视为开发产物。
- 开发母仓 `sand-plugins` 保留全部测试/文档/工具；发布仓是其白名单子集，回潮即违规。

2026-11-01 首次执行：sand-package 164→89、sand-core 565→547、sand-iam 1112→1066 文件；sand-workflow 原本即合规。
