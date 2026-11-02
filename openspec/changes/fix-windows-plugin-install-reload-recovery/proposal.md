## Why

Windows 实机安装 SandIAM 0.8.4 后，插件数据库与文件安装已经完成，但服务重启窗口内 Core 的用户、字典和菜单 GET 经 Vite 代理返回 HTTP 500，管理端永久进入 `/500`。管理员应能从插件管理完成安装并继续使用菜单，无需理解进程重载或手动清除前端失败状态。

## What Changes

- Core 的动态路由初始化对明确短暂的传输失败提供有限恢复，恢复后继续原目标导航。
- 仅重试初始化所需的三个幂等 GET；保留身份失效、无权限、业务错误和无效菜单的现有处理。
- 复用初始化单飞、加载反馈和会话代际失效机制，防止重复注册及旧会话写入。
- 在原有路由行为回归中覆盖恢复、耗尽、权限和并发分支，并由独立执行者复验 Windows 安装入口。

## Capabilities

### New Capabilities

- `core-route-initialization-recovery`：核心路由初始化在短暂服务不可用时有限恢复。

### Modified Capabilities

无；当前现行规格库为空。

## Impact

初轮权威实现候选仅限 `sand-core/sandadmin-artd/src` 内初始化、HTTP 错误元数据及必要 API 参数透传，以及 `sand-core/tests/login-route-recovery-test.cjs`。不改 IAM、数据库、安装事务、Package 重启协议或全局请求重试策略。提供方修改须先由未参与实现者核对本 change 的跨插件必要性论证。该段保留初轮范围，本轮扩展如下；宿主同步、服务操作与发布分别沿用原授权边界。

## 2026-10-05 Follow-up

Core/Package 0.2.3 原生 Windows 安装复现显示：初始化 GET 已恢复、插件页已显示已安装，但全屏遮罩持续超过 100 秒，仓库只读请求也未自动恢复。管理员应从原插件管理入口完成安装，短暂等待后继续浏览已安装及仓库列表，无需手动刷新。

本轮 Impact 包括 Core 既有 loadingService、路由守卫、必要行为测试，以及 Package 仓库两个幂等 GET 的有限恢复与页面请求代际隔离。无 IAM、数据库、安装 POST、Package 重载协议或全局 HTTP 重试变更；不包含提交、版本提升和发布。
