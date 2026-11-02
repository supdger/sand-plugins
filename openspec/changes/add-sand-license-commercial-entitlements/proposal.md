## Why

现有仓库尚无可安装的 SandLicense，旧架构记录也不包含已确认的通用履约、一次领取和会员周期退款规则。需要交付一个中心管理多产品的商业权益插件，以标准包同时交付运营管理和 Webman 运行服务，并复用可用组件、移植成熟业务设计，避免各产品重复建设许可内核。

## What Changes

- 2026-10-04 导航修正：九个商业模块使用宿主左侧菜单与独立路由，移除页内横向模块标签；保留旧 index 地址的有权兼容入口。当前仅授权源码修复和本地回归，不执行数据库、宿主同步、服务或发布。

- 新增独立 SandLicense 产品、套餐、手工发码、首次兑换起算、默认一个可配置团队席位、安装挑战及 Ed25519 短期租约；启动必联网，租约 15 分钟、建议 5 分钟续签。
- 新增受 SandIAM 实际授权的通用履约 API、可信 SKU 映射、一次领取和明确重新签发；仅保存卡密 HMAC 与前缀。
- 新增会员主体权益及逐订单项/计费周期来源 grant，支持幂等履约、乱序退款、取消自动续期和来源隔离撤权。
- 新增中文运营管理、自助领取和可观察恢复提示；源码放入新的独占 `sand-license/`，以单 ZIP 交付插件管理、唯一 `app/SandLicense/**` 内核、配置、生命周期 SQL 及 UI。
- 复用宿主 Permission、异常、数据层及 SandIAM 公开服务授权端口；按固定版本移植 `masterix21/laravel-licensing`、`laravelcm/laravel-subscriptions` 的适用代码流程，不引入 Laravel。同类能力优先沿用宿主实现：JWT由宿主既有`tinywan/jwt`及其传递依赖Firebase提供，许可证独立claims/keys经同一底层公开API薄适配，不单独require/lock/bundle JWT、不新增fallback栈、不修改登录配置。
- 本变更的确认边界替换旧 `docs/architecture/sand-license-boundary.md` 中不一致的目录、旧兼容和长期离线建议；当前不修改旧文档、不迁移旧系统或旧数据库。

## Capabilities

### New Capabilities

- `sand-license-package`: 标准包、唯一运行内核、组件复用和操作者入口。
- `sand-license-online-license`: 手工发码、首兑期限、席位、挑战、租约及释放/重置。
- `sand-license-fulfillment`: 可信履约、商业幂等、一次领取与明确重新签发。
- `sand-license-membership`: 会员来源 grant、周期区间、乱序退款与取消。

### Modified Capabilities

无。官方 CLI 当前未发现现行 specs，本变更不修改其他插件契约。

## Impact

新增 `sand-license/` 及本变更产物；现有 SandPackage 的通用 app/config 精确载荷已支持新产品，无必要跨改。依赖 SandAdmin 宿主、PostgreSQL、SandIAM 和宿主Tinywan/Firebase JWT能力；缺失或不兼容明确失败关闭。当前实测组合仅Tinywan1.15.0/Firebase7.1.1，不将官方兼容范围当成其他版本实测通过。初始源码阶段只授权源码与必要无数据库回归；用户随后明确授权完整临时验收的标准安装/生命周期SQL、隔离临时keys与fixtures、独立测试服务和本次新增资源清理。该授权不涉及持久业务库或既有keys，提交、推送、发布与部署仍未授权；具体目标和逐层证据见tasks，不由本提案自动扩大权限。商城支付、淘宝京东连接器、旧协议兼容、客户客户端重写均不包含。
