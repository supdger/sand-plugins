## Context

需求见 proposal.md。调用方是通用 SandAdmin 宿主，提供方是 Sand Core 的安装器，源码所有者为独立仓库 `supdger/sand-core`；旧 `sand_plugins/sand-core` 为带既有dirty的聚合副本，不作为实施落点；实施基线为公开 Core 0.2.1 / `9bfdfa6682831fe0bdace9734d751dd6b7627b59`，干净候选目录 `/private/tmp/sand-core-install-entry-20261004`。宿主基线由 Windows 执行者固定公开 main `642651f`，提前启动 Vite 时已实际完成安装和登录；Windows执行者已补最小失败复现：官方main642651f/Core0.2.1，3006无监听，数据库安装HTTP200/code200成功，无条件链接localhost:3006/#/auth/login，实际点击三次均net::ERR_CONNECTION_REFUSED；刷新安装页则显示程序已经安装，无法恢复。原始截图已回传 `/private/tmp/sandadmin-windows-frontend-off-completion.png`。原JSONL `/Users/supdger/.codex/sessions/2026/10/04/rollout-2026-10-04T15-45-38-01a105e0-2583-7440-9e49-d6ddfe32d0ae.jsonl` L1354记录main、L1437安装成功及三次ERR、L1478刷新已安装；独立治理审查者已核。

当前 `InstallController::index` 仅解析前端环境文件的 `VITE_PORT`；模板直接按当前协议/主机/该端口赋后台链接，无访问检查。Composer `FrontendPublisher` 只发布源码并初始化 `.env` 和 `.env.development`，不安装 Node 依赖、构建或运行前端。发布源码与运行服务是独立生命周期。

实际使用者是首次安装后台的管理员：从 `/core/install` 输入已准备好的空库信息，安装后使用初始账号进入后台。用户应能确认数据库结果、理解后台尚未可用的具体原因并依页面提供的步骤恢复，恢复后重新检查并进入真实登录页面；不能要求查源码猜端口。

## Goals / Non-Goals

**Goals:** 防止安装成功后直接进入连接拒绝页；让开发和公开部署入口有明确来源，异常恢复步骤可执行并可复测。

**Non-Goals:** 不启动 Node、不增同源静态分发框架、不改数据库初始化、账号密码、鉴权、插件业务或共享治理规则。

## Decisions

1. **提供方通用性论证。** 开发场景为仓库 clone 后前端进程尚未运行；第二场景为标准 Webman 通过 Composer 附加后台并部署至明确公开 URL。两者都需要安装完成后的可用入口和恢复，不绑定 SandIAM、SandAI 或其他可选插件，无新的领域 API/表/权限归属。修复放 Core 安装器；宿主不得修 vendor副本或复制第二套完成页。通用性依据为两种通用宿主使用方式及当前直接导航实现，异常路径实际证据见Context。
2. **保留成功状态与非执行静态检查。** 安装完成不撤回数据库结果。候选新增公开专属 `sandadmin-entry-check.svg`，无脚本、外链，固定113x29特征尺寸；浏览器通过Image解码和naturalWidth/naturalHeight检查，随机query避免旧缓存，有超时及旧回调取消，主按钮点击重新检查。HTML fallback、错误图像、无监听或超时保留完成页。页面仅表述“管理端静态资源已响应”，不确认index/module加载、真实登录、对端身份或业务正常；真实安装登录另验。不得加载Vue、读取token或调用登录/验证码API。普通同源资源请求可能按浏览器策略发送cookie，不把无副作用表述为永不发送cookie。删去iframe/postMessage、meta/module探测、no-cors fetch方案；不弱化CORS/CSP。
3. **配置兼容。** 未显式配置时保留现有端口解析；增加明确的公开后台 URL 配置供独立域名/端口/路径部署。允许同源相对路径或合理 HTTP(S) URL，妥善编码模板数据，避免配置成为脚本或开放代理。前端端口和后台API代理继续各自管理，禁止猜生产反代配置。字段固定为 `SANDADMIN_FRONTEND_URL`，实现测试及文档保持一致，不得新增业务依赖。
4. **恢复路径。** 开发恢复复用项目现有 `pnpm install` / `pnpm run dev` 入口，说明目录、前提与运行输出实际URL；部署模式展示已配置URL及运行部署后台的恢复方向，不误让运营者启动开发服务器。已有前端只是检查失败时仍可重试；按钮不得要求重新安装数据库。已安装刷新只展示入口恢复，不重放初始密码，不改POST安装锁与拒绝重复初始化。Vite启用strictPort，占用时明确失败，禁止静默递增；默认入口兼顾VITE_BASE_URL子路径。
5. **替代方案。** 单改提示不能防止盲跳；安装器自动启动Node越过生命周期且不适合部署；新建同源分发需重做构包、静态资源与反代，超出本次最小修复。因此采用入口检查和恢复，不新增框架。

## Risks / Trade-offs

- 浏览器混合内容、生产CSP img-src和网络策略阻止图像检查 → 明确检查失败，不声称服务未运行；提供已配置目标和可执行恢复/重检及清楚标明“尚未确认”的手工专家出口，仅用户主动选择时使用且绝不自动导航，真实浏览器验收正常与失败路径。
- 检测后服务仍可能退出 → 点击再次检查，保留失败页；访问检查是当时可达证明，不保证持续在线。
- 生产前端地址与开发端口不同 → 公开URL优先，兼容旧端口；覆盖子路径和非localhost域名。
- 新包与消费者锁未同步 → Core发布、SandAdmin约束/锁、正常Composer发布文件、Windows安装登录分别记录。

## Migration Plan

本任务必要版本拟为 Core 0.2.2，最终发行号由主控冻结。先独立审查本通用性记录，通过后在干净Core候选实施并独立验收，再提交feature branch/发布正常版本，更新公共宿主消费约束与锁。常规安装/升级/卸载及依赖方向不变，无SQL变更；回滚至原Core版本及匹配前端源码，不回滚数据库。原始日志/截图存仓库外，规格仅记录证据摘要和绝对路径。正式公开Windows clone安装登录及未启动恢复完成前不归档。

## Provider Review

状态：通过（2026-10-04）。独立审查者 `astramedium__install_governance_review`，实际记录 `gpt-6-astra / medium / openai`；提出者依据审查者原结论记录，不自行评审放行。

审查核对fixed design `54e1f185ab13e812461f9ce8487a755acb3e960ce40df5817c82e3d7f8357909`、tasks `f009c29ad3e82877e08614d6c101df7feae78e3eecb7b7fd891db7d31334e2c9`、spec `92ce6d345c889576d37b5cd03c0cd4d260efd56522047715acd1b9271f2e7e21`及Windows原始记录，结论：通用职责、来源与关键复现成立；非执行专属SVG和静态响应限定解决iframe fallback会话副作用，URL、POST锁、凭据、strictPort、恢复及回滚边界清楚。

本结论只通过提供方治理与最小设计，允许相同边界内实施；不代表候选实现、发行包、消费者或运行验收通过。后续真实Windows登录、生产构建、正常公开消费链仍按tasks完成。相同边界修订不重复请求用户授权。
