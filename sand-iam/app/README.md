# SandIAM 独立应用用户门户

源码在本目录。构建产物应放到插件 `public/account/`，由业务产品选择直接使用或基于 SDK 自建品牌页。

## 边界

- 只调用 `/api/sand-iam/v1/experience`、`/auth/*`、`/me/*`、邀请接受和 OAuth/CAS 人工确认端点
- 邀请 token 从 URL 读出后立刻去掉，不写入 localStorage 或日志；接受成功后必须再登录
- 用户通过登录表单进入账户安全页；会话令牌只在当前页面内存中使用，不发送 `check_admin`，不进入 SandAdmin 菜单
- 品牌名取当前应用，不写死产品名
- OAuth/OIDC 与 CAS 请求会自动识别所属应用、加载该应用的登录体验，并在确认或拒绝后只跳转后端给出的安全地址

## 构建与入口

安装开发依赖后，在本目录执行：

```bash
pnpm install --frozen-lockfile
pnpm run verify
```

构建会直接覆盖插件包内的 `../plugin/sand-iam/public/account/`，不需要手工复制。公开入口固定为 `/app/sand-iam/account/`；当宿主未启用插件静态文件时，插件路由只会提供经审查的 `index.html` 与 `account.js` 两个资源。

登录、OAuth/OIDC 和 CAS 的短期请求、CSRF、会话令牌及恢复码只在当前页面内存中使用。邀请和协议请求从 URL 读取后立刻从浏览器历史移除；不会写入 localStorage 或日志。用户不需要、也不能在 SandAdmin 中粘贴 access token。

## 测试

运行 `pnpm run verify` 检查类型、门户交互契约和正式打包；插件目录的非 PostgreSQL 合同测试进一步覆盖公开入口、OAuth/CAS 跳转及打包完整性。
