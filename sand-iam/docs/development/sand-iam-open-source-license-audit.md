# SandIAM 开源来源与许可证审计（候选方案）

> 审计日期：2026-09-12。状态：**方案待用户确认，不是法律意见，也未写入最终 LICENSE**。
> 审计对象：SandIAM `0.7.0` 当前权威源码、review-only 包策略、锁文件、随包 vendor、SDK 与宿主接口依赖。

## 1. 当前结论

- 当前已识别依赖均为 MIT、BSD-3-Clause 或 Apache-2.0 宽松许可证，未发现 GPL、AGPL、SSPL、BUSL 或其他已知会要求 SandIAM/闭源接入应用同许可证发布的依赖。
- 当前候选已携带 OneLogin PHP SAML、xmlseclibs 和 Composer autoloader 的原许可证文件，以及
  `THIRD_PARTY_NOTICES.md`、`SECURITY.md`、`CONTRIBUTING.md`、公开变更记录和机器可读 SBOM；
  但仍没有经权利人确认的 SandIAM 自身 `LICENSE` 与版权主体，因此不可发布。
- 推荐 SandIAM 自身采用 **Apache License 2.0**：允许商用、修改、再分发和闭源系统集成，并比 MIT 多出明确的贡献者专利许可与终止条款。代价是再分发时需保留许可证、变更/版权/专利归属声明，并维护 NOTICE。
- 备选是 **MIT**：与 SandAdmin 一致、义务更简单，但没有 Apache-2.0 的明确专利许可。若用户优先极简集成而非专利条款，可选 MIT。
- Apache-2.0 与本次识别的 MIT/BSD-3-Clause/Apache-2.0 依赖可并存；第三方代码继续保留各自原许可证，不改成 SandIAM 的许可证。

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
- 当前 `git log -- sand-iam` 显示本仓 SandIAM 提交作者集中为 `supdger`，但这不能代替法律上的版权归属确认，也不能证明所有历史输入均有再许可权。
- 发布前需要用户确认：SandIAM 自身许可证、版权主体显示名/年份，以及是否存在未进入 Git 历史的第三方或雇佣成果权利约束。

## 6. 推荐落地清单（确认后执行）

1. 根与发布包加入标准 Apache-2.0 `LICENSE`，并在包策略中设为必需文件。
2. 若选择 Apache-2.0，加入项目 `NOTICE`：SandIAM 自身归属和必要归属声明；第三方完整索引
   继续由 `THIRD_PARTY_NOTICES.md` 与原许可证承载，不声称第三方背书。
3. 加入机器可读 SBOM（建议 CycloneDX JSON）和 `THIRD_PARTY_NOTICES.md`，区分 vendored/runtime/dev/host-provided。
4. 加入 `SECURITY.md`、`CONTRIBUTING.md`，明确漏洞私下报告通道、贡献默认按项目许可证提交、DCO/CLA 选择。
5. 许可证确认后给可声明许可证的 PHP/TypeScript SDK manifest 补 SPDX 字段；Dart 包以根 LICENSE
   为发布许可证依据。发布构建检查 LICENSE、适用的 NOTICE、SBOM 和 vendor 许可证完整性。
6. 最终候选重新生成恢复描述器、可复现 ZIP、SHA256SUMS 与包外 Ed25519 签名，再做独立许可证复核。

## 7. 待用户确认

- 推荐选择：**Apache-2.0**。
- 需要用户提供或确认的版权行：`Copyright (c) 2026 <法律主体或个人名称>`。
- 需要用户确认贡献治理：轻量 DCO，或要求 CLA。

在以上三项确认前，本任务继续推进不依赖最终许可证文本的源码、测试、文档与候选预门禁，但发布包许可门槛保持未通过。
