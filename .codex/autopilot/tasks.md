# SandIAM P0 自动交付队列

范围：`sand-iam/` 是唯一源码包；`/Users/code/project/sandadmin` 是插件安装、演示与功能验收宿主。仅 PostgreSQL，表前缀 `sand_iam_*`。

协作边界与实时状态：

- 约定：`sand-iam/docs/development/sand-iam-pg-collaboration.md`
- 看板：`sand-iam/docs/development/sand-iam-task-board.md`
- Codex 负责 P0 契约、后端、迁移、运行时鉴权和宿主验收；本队列不含 Cursor 路径。
- Cursor 独占 `sandadmin-artd/src/views/plugin/sand-iam/`；U-01 页面壳已并行完成，不阻塞 IAM-01。
- IAM-01 冻结 Adapter 并标「可消费」后，SandAI `SAND-113C` 即可继续；源码路径 `/Users/code/project/sand_plugins/sand-iam`。

- [x] IAM-01 · Codex · 冻结 P0 领域、PostgreSQL 模型、管理/运行时 API、错误码、SandAI Adapter 与协作交接
  - 证据：[P0 契约 v0.1](../../sand-iam/docs/development/sand-iam-p0-contract.md)；已解锁 IAM-02、Cursor U-02 和 SandAI `SAND-113C`。
- [x] IAM-02 · Codex · 实现 PostgreSQL P0 schema 与插件安装、升级、卸载生命周期
  - 证据：隔离库 `saiadmin_iam_acceptance_20260813` 当前 `0.1.1` 回归安装为 18 张 `sand_iam_*` 表、213 个约束、53 个索引且无非 SandIAM 表；包根 update 成功；uninstall 后为 0 表。SaiPackage 源码证实只执行包根 lifecycle SQL。
- [x] IAM-03 · Codex · 实现控制面与运行时身份上下文 API
  - 验收：组织隔离、应用/环境/工作负载客户端/服务授权、凭证一次展示及轮换撤销、稳定错误码均有接口或自动化验证；Adapter 不跨库读 `sand_iam_*` 表。
  - 已验证：SandAdmin 临时验收服务的后台入口返回 401；运行时未配置 signer 返回稳定 503；回滚事务中已验证 context 签发、校验、授权撤销后的 403 与审计，且测试记录为 0 残留。
  - 边界：真实部署 signer 与已登录管理会话保留给 IAM-05 的宿主端到端验收；本项不生成或落盘密钥、不猜测密码。
- [x] IAM-04 · Codex · 实现策略、数据范围与审计的读写覆盖
  - 已实现：策略/范围契约、身份/角色/资源/策略/管理员组织委派/审计管理接口，以及控制面组织授权限制；`0.1.1` 升级已在隔离库和验收库创建委派表。
  - 已验证：真实验收宿主完成 `list/read/create/update/delete/export/batch` 七种操作允许、跨组织稳定拒绝 `SAND_IAM_ORGANIZATION_ACCESS_DENIED`、角色允许、条件不匹配拒绝、同优先级拒绝优先、无策略拒绝、范围拒绝及审计；两轮 PostgreSQL 测试均回滚且 `residual_org=0`。为兼容宿主进行中的 `saiadmin → sandadmin` 命名迁移，插件只在旧命名空间不可用时注册运行时类别名。
- [x] IAM-05 · Codex · 在 SandAdmin 完成 SandIAM 部署期上下文端到端验收，并向 Cursor 交付冻结接口
  - 已验证：官方 SandAdmin 宿主的插件载荷与源码一致，部署期 signer 已安全写入宿主环境并完整重启生效；临时且已清理的 `saiadmin` 夹具完成运行时 HTTP `issue=200`、`verify=200`、错误 audience `403`、凭证撤销后 `401` 和审计存在验证，9 项夹具/关联审计残留均为 0。交接包已冻结路由、DTO、权限码、错误码与字段字典。
  - 边界：SandAI Adapter 的实际业务 API 放行/拒绝属于 A-01 双插件联调，不把它写成 SandIAM P0 已完成证据。
- [ ] A-01 · Codex + Cursor · 以 SandAI 真实运行 API 验证 SandIAM Adapter 的放行、拒绝与审计路径
  - 前置：IAM-05、U-03；需要独立的 SandAI 联调夹具和验收授权。
