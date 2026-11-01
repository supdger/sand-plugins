# Delta: plugin-install-lifecycle

## MODIFIED Requirements

### Requirement: 兼容性与登记完整性预检前移

上传或仓库下载阶段在解包校验通过后 SHALL 校验：插件 `info.ini` 的 `support` 与当前宿主版本兼容、`config/app.php` version 与 `info.ini` 一致、依赖声明可解析且每个声明依赖至少存在一条可满足路径（见"声明依赖的多路径满足"）。校验失败 SHALL 拒绝形成候选并给出携带实际值（插件声明 `support`、当前宿主版本、来源文件、未满足依赖及其可行路径）的错误。

#### Scenario: 不兼容插件在上传阶段被拒

- **WHEN** 用户上传 `support = ">=0.2.3"` 的插件而宿主为 0.1.0
- **THEN** 请求被拒并返回形如 `插件要求宿主 >=0.2.3，当前宿主 0.1.0` 的错误，不产生 WAIT_INSTALL 候选

#### Scenario: config/app.php 版本不一致被拒

- **WHEN** 包内 `info.ini` 与 `config/app.php` 的 version 不一致
- **THEN** 上传被拒并指明两处版本值与文件，不产生候选

#### Scenario: 声明依赖无 bundle 但已在宿主满足

- **WHEN** 包声明 `plugin_dependencies.sand-iam = 0.8.4` 且不含 `dependencies/` 工件，而宿主已注册 `sand-iam` 且版本满足声明的最低版本语义
- **THEN** 预检通过并形成候选，不因缺 bundle 拒绝

#### Scenario: 声明依赖无任何满足路径被拒

- **WHEN** 包声明依赖且无 bundle、宿主未注册满足版本依赖、也无已上传依赖候选
- **THEN** 上传被拒，错误列出缺失依赖名、所需最低版本与可行路径（上传依赖包/携带 bundle）

## ADDED Requirements

### Requirement: 声明依赖的多路径满足

声明 `plugin_dependencies` 时，安装器 SHALL 按序尝试满足：宿主已注册依赖（最低版本语义）→ 已上传依赖候选 → 随包 `dependencies/<app>-<version>.zip` bundle → 引导上传依赖包。任一路径满足即放行；bundle 存在时 SHALL 保持 exact version 与 sha256 身份校验不变。

#### Scenario: 已注册依赖优先于 bundle

- **WHEN** 宿主已注册 `sand-iam` 0.9.0，而包内 bundle 为 0.8.4
- **THEN** 安装放行已注册版本，不降级、不重装；bundle 若存在仍须通过 sha256 校验但不参与安装

#### Scenario: bundle 作为干净库一键路径

- **WHEN** 干净库安装且包内携带合法 bundle
- **THEN** bundle 被消费为依赖候选并照常安装或进入现有 schema 重接流程

#### Scenario: 依赖为已安装插件时卸载受保护

- **WHEN** 某依赖的满足源是已注册插件且其他已安装插件仍声明依赖它
- **THEN** 卸载该依赖插件被拒并指明依赖方

### Requirement: 发行包依赖工件可选化

插件发行方 SHALL 可以选择不在包内携带依赖 bundle；无 bundle 的包 MUST 与携带 bundle 的包同等可安装，只要声明依赖按上条路径满足。安装器 MUST NOT 以"缺少 `dependencies/` 目录"本身作为拒绝理由。

#### Scenario: 无 bundle 包在依赖已就绪宿主安装

- **WHEN** 发行包不含 `dependencies/` 且宿主依赖已满足
- **THEN** 上传、安装、升级全流程与带 bundle 包一致，不额外要求工件

#### Scenario: 无 bundle 包在干净宿主引导上传

- **WHEN** 发行包不含 `dependencies/` 且依赖未满足
- **THEN** 错误引导上传依赖包；上传合法依赖后原安装可继续，不必重传本包
