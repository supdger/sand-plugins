# frontend-resilience

## Purpose

管理前端在任何启动/渲染/导航/初始化失败下都必须呈现可诊断的可见状态，永不白屏；一次性失败可重试、可恢复，不形成永久死局。

## ADDED Requirements

### Requirement: 全局静态错误边界

前端 SHALL 存在一个**不依赖自动导入组件**的静态错误边界：包裹根路由视图并在应用 bootstrap 提供兜底。任何渲染错误、setup 抛错、bootstrap 失败、模块求值失败 SHALL 渲染可诊断错误页（含错误摘要与重载按钮），不得呈现空白 DOM。

#### Scenario: 布局组件解析失败不白屏

- **WHEN** `ArtSidebarMenu` 等布局组件在运行时无法解析（watcher 映射丢失、文件缺失）
- **THEN** 错误边界渲染带错误信息与"重载"操作的页面，而非空白

#### Scenario: bootstrap 失败兜底

- **WHEN** `main.ts` 任一初始化步骤抛错或 `app.mount` 失败
- **THEN** `#app` 写入静态兜底标记（错误说明 + 重载按钮），非纯白

### Requirement: 布局组件确定性解析

布局关键组件（`ArtSidebarMenu`、`ArtHeaderBar`、`ArtPageContent`、`ArtGlobalComponent`、`ArtLogo` 等 `AppLayout` 直接依赖项）SHALL 通过静态 import/全局注册解析，不依赖 dev-server watcher 内存映射；目录被整体 `rename` 交换后布局 SHALL 在下次编译时仍可解析（不经 dev 重启也仅受编译期正常机制影响）。

#### Scenario: 目录交换后布局仍可用

- **WHEN** `src/components` 目录被原子交换后用户刷新页面
- **THEN** 布局组件正常解析渲染，不产生 `Failed to resolve component` 白屏

### Requirement: 全局加载遮罩必须有超时兜底

全屏 loading SHALL 带绝对看门狗（硬上限超时后强制关闭并显示可诊断提示）；任何 owner/flag 不一致、热替换、异常路径 SHALL NOT 让遮罩永久残留。

#### Scenario: 初始化挂起不永久遮罩

- **WHEN** bootstrap 的 API 调用挂起超过上限
- **THEN** 遮罩被强制关闭并显示带重试操作的超时提示，用户不被锁死

### Requirement: 懒加载 chunk 失败可恢复

动态 import/`vite:preloadError`/路由懒加载失败 SHALL 显示带"刷新重试"的可见提示（含一键 `location.reload`），不得静默终止导航留空屏。

#### Scenario: 部署后旧 hash 资源 404

- **WHEN** 浏览器缓存的旧 index.html 请求已不存在的 hashed chunk
- **THEN** 显示"资源已更新，点击重载"类提示与重载按钮

### Requirement: 路由初始化失败可重试

路由初始化失败的粘性状态 SHALL 可被用户显式重试清除；错误页的"重试/返回首页" SHALL 在导航前重置初始化状态，不得形成 /500 死循环。

#### Scenario: 瞬时后端故障后可恢复

- **WHEN** bootstrap 因一次瞬时 500 进入 `routeInitFailed`
- **THEN** 用户在错误页点重试即重新初始化路由，不卡在死循环

### Requirement: 兜底重定向可见化与目标校验

路径未匹配兜底重定向到首页时 SHALL 给出可见提示（toast/短提示页），不得静默吞掉；`findDefaultMenuPath` 选中的目标 SHALL 先验证可解析再返回，否则回落到已确认存在的路径。

#### Scenario: 未授权 URL 不静默跳首页

- **WHEN** 用户输入存在但无权限/未注册的 URL
- **THEN** 落点前有"该页面不可用，已返回首页"类可见提示

### Requirement: 持久化状态迁移校验与腐坏自救

跨版本 localStorage 迁移 SHALL 做形状/版本校验而非盲拷；存储腐坏检测 SHALL 实际可达（登录态正确传入），且提供用户可操作的"重置本地状态"出口。

#### Scenario: 回滚跨版本状态损坏

- **WHEN** 回滚后旧版持久化数据形状不符
- **THEN** 应用检出损坏并引导重置，而非静默喂给布局组件坏数据

### Requirement: 环境变量缺失显式失败

`VITE_API_URL`、`VITE_VERSION` 等关键 env 缺失时 build/dev 启动 SHALL 明确报错而非代入 `undefined`；`.env.example` SHALL 列出全部必需变量（含 `VITE_API_PROXY_URL`）。

#### Scenario: 缺 env 不静默坏

- **WHEN** `.env` 缺 `VITE_API_URL`
- **THEN** 启动即报缺失变量名，而非 baseURL=undefined 引发连锁 404
