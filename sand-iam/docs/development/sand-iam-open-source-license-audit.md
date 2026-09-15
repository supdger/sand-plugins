# SandIAM 开源来源与许可证审计（0.7.2 当前基线）

> 审计日期：2026-09-12。状态：**Apache-2.0、Copyright 2026 supdger 和 DCO 已在权威源码落地**。
> 本文不是法律意见；最终签名、来源权利链和正式发行仍须独立复核。
> 审计对象：SandIAM `0.7.2` 当前权威源码、发布包策略、锁文件、随包 vendor、SDK 与宿主接口依赖。2026-09-15 仅刷新候选版本；依赖坐标和许可结论未变。

## 1. 当前结论

- 当前已识别依赖及随包 schema 许可为 MIT、BSD-3-Clause、Apache-2.0 或 W3C Software Notice and License，未发现 GPL、AGPL、SSPL、BUSL 或其他已知会要求 SandIAM/闭源接入应用同许可证发布的依赖。
- SandIAM 自身以 **Apache License 2.0** 发布，`NOTICE` 载明 `Copyright 2026 supdger`，贡献治理采用
  DCO。Apache-2.0 允许商用、修改、再分发和闭源系统集成，并提供明确的贡献者专利许可与终止条款。
- 当前候选携带 OneLogin PHP SAML、xmlseclibs、Composer autoloader 及 W3C XML Signature Core Schema
  的适用许可/notice，并包含 `THIRD_PARTY_NOTICES.md`、`SECURITY.md`、`CONTRIBUTING.md`、公开变更记录和机器可读 SBOM。
- Apache-2.0 与本次识别的 MIT/BSD-3-Clause/Apache-2.0/W3C Software Notice and License 依赖可并存；第三方代码继续保留各自原许可证，不改成 SandIAM 的许可证。

## 2. 随安装包分发的运行时依赖

| 组件 | 锁定版本/来源 | 许可证 | 分发形态 | 必须保留 |
| --- | --- | --- | --- | --- |
| `onelogin/php-saml` | `4.3.2`；`plugin/sand-iam/composer.lock`；[官方仓库](https://github.com/SAML-Toolkits/php-saml/tree/4.3.2) | MIT | 随包 vendor | 原 LICENSE 与版权声明 |
| `robrichards/xmlseclibs` | `3.1.5`；Composer transitive；[官方仓库](https://github.com/robrichards/xmlseclibs/tree/3.1.5) | BSD-3-Clause | 随包 vendor | 原版权、三项条件与免责声明；不得暗示作者背书 |
| Composer autoloader | 当前 vendor 生成物；[Composer 官方仓库](https://github.com/composer/composer) | MIT | 随包 vendor | `vendor/composer/LICENSE` |

当前包策略实际只分发上述三份第三方 LICENSE。发布包必须继续包含它们，并由统一第三方声明索引到原文件。

## 3. SDK 与构建依赖

| 范围 | 当前版本 | 许可证 | 发布影响 |
| --- | --- | --- | --- |
| Dart `args` | `2.7.0` | BSD-3-Clause | SDK 运行依赖；保留依赖清单与来源 |
| Dart `http` | lock 为 `1.6.0`，约束 `^1.5.0` | BSD-3-Clause | SDK 运行依赖；其 runtime closure（`async`、`collection`、`http_parser`、`meta`、`web`、`source_span`、`string_scanner`、`typed_data`、`path`、`term_glyph`）当前缓存许可证均为 Dart BSD-3-Clause 模板 |
| TypeScript | `5.9.3` | Apache-2.0 | portal/TS SDK 构建期；node_modules 不入包；官方 5.9.3 [LICENSE](https://github.com/microsoft/TypeScript/blob/v5.9.3/LICENSE.txt) |
| esbuild | `0.25.10` | MIT | portal 构建期；平台二进制包不入候选 |
| Dart `lints` / `test` 及测试闭包 | lock 文件固定；BSD-3-Clause 为主 | 开发/测试期 | 不作为运行时随包代码；SBOM 仍应区分 dev/runtime |

Dart 锁文件当前共有 49 个组件：47 个为 BSD-3-Clause，`node_preamble 2.0.2` 为
`BSD-3-Clause AND MIT`，`yaml 3.1.3` 为 MIT。npm 锁文件合并去重后为 28 个组件：
TypeScript 为 Apache-2.0，esbuild 及其 26 个
平台包为 MIT。`tools/dependency-license-policy.php` 以精确 `name@version` 坐标固定这些结论，
SBOM 为全部 79 个组件写入 SPDX 标识和逐组件许可证证据 URL；锁文件出现未审计坐标时生成器
关闭失败。发布前仍必须从最终 lock 重算，不能把本表当成永久清单。

## 4. 宿主与管理端接口依赖

| 组件 | 当前约束/版本 | 许可证 | 边界 |
| --- | --- | --- | --- |
| SandAdmin | H1 revision `558d92959947230ee562f29e015c62566be58c8e` | MIT；仓库含 SaiAdmin/Webman 等归属 NOTICE | 宿主，不复制进 SandIAM 包 |
| Webman | 宿主提供 | MIT | 运行接口依赖，不随 SandIAM 重打宿主源码 |
| Vue | host `^3.5.21` | MIT | 管理端源代码由宿主构建 |
| Vue Router | host `^4.5.1` | MIT | 管理端源代码由宿主构建 |
| Element Plus | host `^2.11.2` | MIT | 管理端源代码由宿主构建；官方声明为 [MIT](https://github.com/element-plus/element-plus) |

SandIAM 的安装包可以包含插件管理端源码，但不得把整个 SandAdmin 或宿主 node_modules 当成自身发行物。

## 5. 对照产品与来源边界

- Casdoor 只用于功能与旅程对照，不复制其实现。Casdoor 官方仓库使用 Apache-2.0；对照报告需保留来源链接，不能据此主张 SandIAM 代码来源于 Casdoor。
- 当前 `git log -- sand-iam` 显示本仓 SandIAM 提交作者集中为 `supdger`，但这不能代替对所有历史输入、
  雇佣成果或仓外第三方材料的权利链复核。

## 6. 已落地材料与最终发行前复核

1. 根与三个可独立分发 SDK 都携带 Apache-2.0 `LICENSE` 与 `NOTICE`；PHP/TypeScript 元数据声明 SPDX，
   Dart 包保留其支持的 repository、homepage 和 issue tracker 元数据。
2. `THIRD_PARTY_NOTICES.md`、CycloneDX SBOM 与发布卫生检查共同覆盖 vendored/runtime/dev/host-provided
   依赖，以及 W3C XML Signature schema 的独立 notice。
3. 最终候选必须从最终 clean commit 重新生成 SBOM、可复现 ZIP、SHA256SUMS 和包外 Ed25519 签名，
   再由独立人员复核许可证文本、notice、SBOM、来源权利链与签名身份。
4. 已落地的许可证和 DCO 不替代安装升级、宿主验收、业务闭环、外部互操作、Casdoor 对照或 24 小时稳定性门槛。
