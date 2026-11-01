# Tasks

## 1. 依赖解析多路径

- [ ] 1.1 `InstallLogic`/`verifyBundles` 重构：声明依赖先走已注册→已上传候选→bundle→引导上传；无 bundle 不再作为拒绝理由
- [ ] 1.2 bundle 存在时保持 version+sha256 身份校验；未满足路径结构化错误（依赖名、最低版本、可行路径）进 ApiException details
- [ ] 1.3 `preparePluginDependencies` 仅在无已注册满足时消费 bundle/候选；已注册版本优先、不降级

## 2. 联动与边界

- [ ] 2.1 卸载保护：依赖满足源为已注册插件时阻止卸载并指明依赖方
- [ ] 2.2 现有 schema 重接：无 bundle 场景保持要求另行提供依赖包/清单；文档与错误引导一致
- [ ] 2.3 `sand-package` 文档/契约更新：依赖声明语义、可选 bundle、发行方建议

## 3. 验证

- [ ] 3.1 单元/契约测试：三路径满足、拒绝诊断、bundle 校验回归
- [ ] 3.2 真实宿主验收：无 bundle 包在依赖已注册宿主安装；干净库 bundle 一键路径；无 bundle 引导上传后完成安装；卸载保护负例
- [ ] 3.3 回归：SandAI 0.3.0 带 bundle 包在升级宿主安装不受影响

## 4. 论证闭环

- [ ] 4.1 未参与实现的审查者按 `docs/cross-plugin-changes.md` 核对证据并给出通过/退回结论；通过前不进入实现
