## 1. 提供方论证与基线

- [x] 1.1 独立审查者核对通用性、Windows前端off失败证据及边界，在design Provider Review记录通过或退回，未通过不得修改Core产品。
- [x] 1.2 冻结Core0.2.1源码、宿主revision、独立候选目录及修复版本，核对git clean并保留原工作区dirty。

## 2. 完成页与入口契约

- [x] 2.1 实现VITE_PORT/VITE_BASE_URL及SANDADMIN_FRONTEND_URL，测试合法同源路径、HTTP(S)URL、非法协议拒绝与安全编码。
- [x] 2.2 实现无脚本外链的专属SVG静态标记及Image decode/尺寸检查、超时取消与点击重检，测试无监听、HTML fallback、错误尺寸和旧回调均不误判；仅声明静态资源响应，失败保持完成页，不改Vue/index/CORS/CSP。
- [x] 2.3 提供开发与部署恢复步骤，已安装刷新仍可恢复且不重放初始密码，实际浏览器刷新重检、PHP重复POST前置拒绝且配置不变，保持既有数据库和账号写入路径。
- [x] 2.4 启用strictPort，测试端口占用明确失败、自定义端口及子路径和入口配置一致。
- [x] 2.5 同步受影响README/Wiki与CHANGELOG，核对命令、配置名、检查范围和正常部署兼容说明。

## 3. 候选独立验收

- [x] 3.1 Windows从/core/install真实执行前端off安装、失败保留、启动、重检、进入登录并登录成功，保存仓库外截图日志和revision。
- [x] 3.2 独立验收默认及自定义端口、子路径及公开URL、静态图像超时及错误资源和已安装刷新，分别记录入口响应与真实登录结果。
- [x] 3.3 执行相关PHP/前端行为测试、构建及独立验收，证实无数据库迁移、密码重置和Node自动启动。

## 4. 正常发行与消费闭环

- [x] 4.1 实际暂存索引审查及hook检查后提交Core feature branch，发布冻结正常版本并重下载校验源和工件。
- [x] 4.2 公共SandAdmin更新Core约束与锁，公开干净clone默认Composer安装核验实际模板、专属SVG和匹配管理端文件。
- [x] 4.3 Windows按合并公开版本重走未启动恢复与登录，核对任务测试库和进程清理并保留证据，闭环完成前不归档。

## 实施候选（2026-10-04）

- 独立提供方仓 `/private/tmp/sand-core-install-entry-20261004`，分支 `codex/install-admin-entry-readiness`，候选提交 `506f9ef367202023053d7e3e22b31c11cb025783`；父聚合仓和既有 dirty 未改。
- `node --test tests/install-entry-test.cjs`：17 项通过，覆盖合法/非法 URL、IPv6、子路径、错误图像、超时、旧回调、失败留页及点击重检。图像网络解码仍待真实浏览器验收。
- `php tests/install-entry-render-test.php <宿主 vendor/autoload.php>`：10 项通过，真实 Twig 渲染、环境覆盖、已安装不输出凭据、属性转义、POST 重复安装拒绝、标准 Webman/Windows 发布路径；原 `install-database-errors-test.php` 29 项通过。
- 复用已安装依赖，执行项目 build 的相同 `vite build` 和 `vue-tsc --noEmit` 均通过；Vite 13.16 秒、2976 modules，dist 标记与源码一致。全局 pnpm 11 拒绝对复用的外部 node_modules 自动重装，因此不宣称正式 `pnpm run build` 脚本或全新依赖安装已通过；Windows 正常消费将补验。
- 实际 Git pre-commit 已接入并检查索引，仓库内容审查绑定 tree `20a799998d5e22f8ad3f18632fdc979a9ad34a7e` 后提交。未推送、未发布，候选 ZIP `/private/tmp/sand-core-0.2.2-install-entry-candidate.zip`，SHA-256 `07f3307964d4b57487bef0c43b40c73ca8b9e939efe9aef36febbaafa43eb267`。
- 2.2/2.3/2.4 产品已实现，仍保留未勾选直到 Windows 真实网络、刷新、恢复与 strictPort 验收；2.5 的 Core README/CHANGELOG 已改，Wiki 由文档执行者同步。

### 候选返工更新

- 当前固定候选替代前述 `506f9ef`：`5956228296e419814996ae13af4f37bd807ef22d`，路径与分支不变。ZIP 同路径已替换，SHA-256 `b0b0cde0c60f7041461bc8f5152be707ed517982ac67e3fe68c772200369f33c`。
- 恢复页、README 与 packageManager 对齐已实测 pnpm 11.19.0；Node.js 推荐已验证的 22（>=22.12.0），保留原 Node.js >=20.19.0 声明，不从只测 22 推定旧版不兼容。依赖和锁文件未变，Windows 执行者将按页面命令验证固定工具链。
- PHP 渲染前拒绝非法 scheme、userinfo、控制字符和反斜杠，丢弃原始值，只输出通用配置错误；前端不猜默认入口，允许输入合法地址恢复。真实 Twig 渲染 18 项通过，包含秘密零回显及同源路径/HTTPS/IPv6；JS 17 项通过，增加非法配置无探测请求和合法输入恢复验证。未受影响的数据库错误、生产构建与类型检查复用。
- 补提交各自记录与新暂存树绑定的内容审查；最后 tree `636a259bc774afacc46f9bd5193dd1dce4cb0fa5`，实际 pre-commit 通过。未推送、未发布，Windows与独立产品验收仍待完成。

### Core 0.2.2 正式发布

- 独立候选 Windows 与正常 pnpm 构建通过后，feature 分支经 PR https://github.com/supdger/sand-core/pull/13 合并；merge `80c70c85727e91117d7ce5e373b5864f0f3e7be2` 的树与固定候选一致。tag `0.2.2` 明确指向已验收 `5956228296e419814996ae13af4f37bd807ef22d`，未直接推 main，未覆盖既有 tag。
- 正式 Release https://github.com/supdger/sand-core/releases/tag/0.2.2 为非草稿、非预发布、latest；附 `system-update.json`，host_min=0.2.0，无 schema/skeleton 变更。发布前与下载后均逐项核对 549 个载荷摘要；契约 SHA-256 `3794e1e4a2269c61cd2d1a067d4f60079271afc9a0663dbc3b8ed53a157552ba` 与 GitHub asset digest 一致。
- 无认证公开下载源码 ZIP 与契约通过，包含静态标记、入口 JS/PHP 与 pnpm11.19 声明；Packagist 公开 p2 已列 0.2.2，source/dist reference 均为 `5956228` 完整提交。证据 `/private/tmp/sand-core-022-public-readback`。
- Core 仓库当前无 Actions workflow/check-run，未声明 CI 通过。提供方发布和公开摘要检查不替代 4.2/4.3 的宿主锁定、普通 Composer 安装与公开版本 Windows 运行验收。


### SandAdmin 0.2.1 正常发行与公开消费

- 宿主PR https://github.com/supdger/sandadmin/pull/42 合并为 `830da4b1f6d46a45406339f76b09ffd4923fb191`，tree与独立审查的feature提交 `9091c640e30054564420f305fdb5e87efdb1c4b8` 相同。实际索引tree `ae7762ef42e9de6f2ec1127f11b27a19851ac38a` 绑定审查及pre-commit通过；非直接推main，无CI workflow/checks不宣称CI通过。
- 正常tag `0.2.1` 与Release https://github.com/supdger/sandadmin/releases/tag/0.2.1 已公开，未覆盖既有版本。Core最低约束 `^0.2.2`，精确source/dist为 `5956228296e419814996ae13af4f37bd807ef22d`；Package仍为 `0.2.2@4d4d2607602b5b63b3aa06bfe7a0aa4d58af3847`，其余全部依赖不变。
- macOS从公开tag重新clone至 `/private/tmp/sandadmin-0.2.1-public-fresh-20261004`，默认 `composer install --no-interaction --prefer-dist` 安装102包并执行全部正常发布脚本。严格校验通过；实际Core397项与Package9项前端文件摘要、核心controller/template/JS均与正式包一致；git仍clean，server/.env不存在，无数据库或服务动作。结果 `/private/tmp/sandadmin-0.2.1-public-fresh-result.json`。已安装正式包17项JS及18项PHP/Twig回归通过。
- 重下载公开自动源码ZIP：6506252字节、473文件、CRC通过，SHA-256 `86fbeac7d0e54cd6e4f284468cffe498edbfe96b6b6f6112f09c88febfd3d6a1`；正式锁、pnpm固定声明、静态标记、版权文件均包含，锁与SVG等与公开clone一致。路径 `/private/tmp/sandadmin-0.2.1-public-source.zip`。正式Release/源码8个关键链接HTTP200。
- Wiki受影响5页经独立Astra文档复核通过，本地22页96目标0错误/警告。对外提交仍待公开Windows验收事实并入；4.3、2.5等未完成事项不提前勾选，不归档。

### 受影响 Wiki 正式同步

- 同产品既有 Wiki 5 页 Home/getting-started/plugin-updates/troubleshooting/version-scope 经独立 Astra 文档审查，正式版本链接核验后发布。当前安装指引使用 pnpm11.19.0、正确 Windows/Unix Webman 命令、SANDADMIN_FRONTEND_URL 与 strictPort 恢复；旧 pnpm9.15.9 仅作为明确历史记录保留。
- 实际暂存 tree `7d7eb428422d3dd4aa1384e6983815802fca5c82` 绑定内容审查，正常 pre-commit 通过；Wiki提交 `6d0bfbe407b3b2a157ef4c7f8bc86fa4062a5ad1` 已推送，远端HEAD一致，无认证公开 version-scope HTTP200且包含本次公开 Windows 开发模式实装与登录事实。22页96本地目标检查0错误/警告；关键正式版本链接另有HTTP200证据，不声称所有外链已验。
- 公开 Windows 运行事实由主控提供：正式Host0.2.1/Core0.2.2新空任务库安装200/code200，前端off留页恢复，正常pnpm启动后重检并普通验证码登录成功，用户/字典/菜单/统计接口200/code200。最终截图与任务临时资源清理仍由Windows执行者/主控收尾；不据此提前勾选4.3或归档。

### 最终验收及清理（2026-10-04）

- 独立候选验收目录 `/private/tmp/sandadmin-independent-ui-20261004/evidence/`：开发模式普通登录与工作台（05-dashboard）、已就绪后停前端再次点击仍留页（06-off-after-ready）、错误服务不误判（07-wrong-service）、CSP限制仅无法确认（09-csp-unverified）；正式生产构建产物完整HTTP入口33006（11-production-login）及同源/admin子路径（13-same-origin-login）能展示登录表单和验证码。`normal-production-build.log` 记录正常Vite构建与vue-tsc成功。Windows3006占用fixture下候选strictPort明确exit1，未漂移监听3007。以上由独立Astra及Windows执行者核验，并由主控确认固定595候选对应。
- 验证层次：17项JS覆盖错误尺寸、超时、取消和旧回调；18项PHP真实Twig渲染覆盖已安装GET不回显初始凭据及重复POST前置锁拒绝且配置不变，拒绝测试无数据库连接。既有29项数据库错误回归通过。没有另做真实数据库账号快照对比，不将前置拒绝单测冒称此结果；既有数据库/账号写入路径未变，无新增迁移、密码重置或自动启动Node。
- 公开Windows最终证据 `/private/tmp/sandadmin-windows-public-acceptance-20261004.md`：正常公开Host0.2.1@830da4b/Core0.2.2@595消费、102包Composer及870包pnpm固定锁安装，独立新空任务库实际安装200/code200；前端off留页恢复，启动后重新检查并真正进入3006登录，使用人工可见验证码普通登录获得token，用户/字典/菜单/统计接口全部200/code200，工作台DOM及菜单截图证实。已安装入口关闭Vite后仍恢复完整步骤。截图 `sandadmin-windows-public-after-login.png`、`sandadmin-windows-public-off-recovery.png` 均在/private/tmp。
- 收尾已核：本任务Vite/PHP进程树及PG guardian均停止，postmaster.pid不存在；8787/3006/3007/33006/25435无监听。任务PG测试cluster/库/密码和上传候选TEMP资源已删除，任务server/.env不存在；正常源码及vendor/node_modules保留，D:/htdocs/sand_plugins保留，gitclean，官方及工作锁摘要一致。无持久运行演示库或服务遗留。
- 未执行/不声明：实际生产站点完整认证与业务链、既有业务库迁移或部署、全平台登录、全部Wiki外链与锚点。生产构建产物仅入口/验证码展示，真实完整登录证据为Windows开发模式。当前13项交付任务全部已按上述测试层次完成；变更保持未归档。
