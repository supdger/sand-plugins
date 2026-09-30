## Context

范围与动机见 proposal.md。受影响页面位于 SandIAM 管理端；首次向导只跳路径，TaskPath 当前统计所有可见记录。后台 AdminResourceController 对 writeFields 中的 application_id/environment_id 直接筛选；WorkloadClientController 和 ServiceGrantController 另支持 application_id 关联筛选，均与访问范围相交。凭证的自定义 index 也已提供 application→environment→client 关联筛选，身份源按应用挂载关系筛选，二者均与管理访问范围相交。全局服务等对象不能据此推断支持 application_id，因此仅对白名单中已逐项核实的 endpoint 进行任务计数。

SandAI 通过 HostSandIamRuntime 消费公开端口。authorizeInvocation、revalidateInvocation 各九参数；SandAI 校验 service_code=sand_ai、audience=sand-ai、作用域和授权返回类型。当前 SandAI 锁 IAM 0.7.5 与 SHA-256，而 IAM 源码 metadata 为 0.7.3，需要核查包及真实加载路径。

## Goals / Non-Goals

**Goals:** 在现有能力内修复八项审计断点，为管理员和开发者分别给可理解的顺序、输入来源、结果和恢复；以当前应用可验证范围组织状态与新建默认值。

**Non-Goals:** 新接口、字段目录自动发现、部门动态比较算法、密码或账号绑定协议变更、数据库/SQL变更、SandAI代码改造、锁定依赖更新、测试宿主升级或公开发布。

## Decisions

1. 查询参数承载当前客户主体/应用/环境的已选上下文。仅接受正整数，读取既有可见 API 核对归属；无权限、篡改、不匹配或过期上下文显示明确错误并清除不相容默认。沿用原路由，不新增 API 或假定前端是授权边界。
2. 复用现有任务 definitions，补充连续任务说明/下一入口。TaskPath 使用受支持筛选；不能范围查询的步骤要求进入页面检查。状态用“有记录，待验证”，不依据数量或 localStorage 声称登录、权限、调用完成。
3. ResourceEditor 的归属预填来自受控初始值或页面筛选上下文；切换应用同步清理依赖对象。一次性凭证沿用只显示一次、只写不读的安全行为，附非敏感归属摘要。
4. 条件和数据范围说明使用应用开发者明确提供的字段和值；以真实支持的静态字段/范围为例，不许宣称 IAM 自动执行未证实的同部门查询。必须说明应用侧可信事实与列表过滤责任。
5. 错误映射仅精确稳定码与已确认非敏感校验白名单；未知服务端文本不直出。人员注册/邀请及服务受众的文档必须核对源码/SDK，缺口说明前提，不发明接口。
6. 条件编辑器在既有 equals/in 标量协议内为字符串/数字/布尔/null 提供类型选择与保真 hydration。修复原字符串化导致严格匹配改变的问题；非法输入阻止保存，不改 PHP matcher 或数据库。
7. UI 工作包只改管理端与适用 helper 测试；文档工作包只改受影响 README、既有必要源码相邻说明及 Wiki 草稿（仓库外）。两者都读同一spec。兼容工作包只读实际包/安装路径与版本、运行无DB离线测试，并检查最终SQL/runtime无差异。

## Risks / Trade-offs

- [记录计数仍不能证明业务可用] → 用中间状态展示，并列出实际登录/允许/拒绝/撤销/审计成功标准。
- [查询参数伪造导致错误默认] → 正整数解析、可见归属读取、匹配检查及服务端既有授权；不以URL参数拓权。
- [源码旧metadata与依赖新包混淆] → 精确包摘要和有效路径核对，禁止降级或改SandAI依赖锁；发布阶段单独固定兼容基线。
- [缺真实已安装环境] → 先完成源码/离线检查和独立文档模拟，按本轮实际结果报告界面及业务验证缺口。
- [文档示例被误当自动业务能力] → 明确示例责任方、真实字段来源、现有支持边界和应用侧过滤职责。

## Migration Plan

本次不新增数据迁移，不执行SQL或宿主升级。后续发行采用现行可信包生成及 SandPackage 备份升级路径；以已安装版本为前提，核验版本前进、生命周期摘要、旧凭证/授权/上下文消费及回退备份。源码整改完成不能替代这些发行检查。
