## Why

管理员完成数据库安装后点击“进入管理后台”，当前完成页直接跳向独立前端端口；前端未运行时离开成功页并得到 ERR_CONNECTION_REFUSED。Windows 最新公开宿主提前启动 Vite 后安装和登录成功，仅证明就绪路径，不覆盖缺失前端的恢复路径。

## What Changes

- 完成页将数据库安装结果与管理端静态资源响应分开显示，在无法确认访问时保留页面并提供可执行恢复步骤及重新检查。
- 开发模式复用现有前端端口配置，公开部署支持明确配置的后台 URL，避免无条件拼接开发端口。
- 增加未启动、恢复后重检、默认和自定义入口的真实 Windows 验收；保持安装凭据、数据库流程与登录鉴权不变。

## Capabilities

### New Capabilities
- `install-admin-entry-readiness`: 安装完成后的后台访问检查、开发恢复与明确部署入口。

### Modified Capabilities

无。

## Impact

提供方为 Sand Core 0.2.1，公开源码 revision `9bfdfa6682831fe0bdace9734d751dd6b7627b59`。修改安装完成模板、必要的入口配置读取、相关行为测试和受影响 README/Wiki；宿主通过新版 Core、Composer 约束与锁文件消费。无新依赖、数据库结构变更、Node 自动启动或同源后台分发框架。
