## Purpose

应用保持既有身份、角色、策略发布和数据范围配置，无需重填；成熟策略内核负责唯一裁决，加载与模拟具有一致事实、可追溯解释和安全失败边界。

## ADDED Requirements

### Requirement: 唯一策略裁决与既有顺序
系统 SHALL 以实际 Casbin 普通 Enforcer 裁决应用策略，不以旧引擎输出作为模型函数。最终响应 SHALL 保持命中最小 priority 层、同层 deny 优先及全 allow 最小 policy.id 的单 scope。

#### Scenario: 同层 deny 与不同层 deny
- **WHEN** priority=10 的 allow 与 priority=20 的 deny 均命中
- **THEN** 系统允许；添加 priority=10 的 deny 后拒绝；多个 allow 不合并 scope

### Requirement: 已发布事实与一致撤权
系统 MUST 从同连接短只读可重复读快照读取启用状态、发布指针、不可变版本和有效关系，不消费草稿字段，不将异常主体转为公共授权。撤销已提交后取得的新快照 SHALL 拒绝。

#### Scenario: 草稿与撤销
- **WHEN** state=draft 但旧发布指针仍存在且 status=1
- **THEN** 旧 snapshot 保持生效；status=2 后不再加载

#### Scenario: 运行事实异常
- **WHEN** 主体为空/双主体、版本丢失、跨应用引用或加载异常
- **THEN** 本次应用授权拒绝，不静默跳过异常后允许

### Requirement: 模拟与实际裁决一致
模拟 SHALL 使用相同模型、关系、条件函数和原 effect 获取最终结果与每条目标规则的匹配解释，保留未命中规则、缺失上下文及脱敏输出，无审计写入。

#### Scenario: deny 命中与不命中
- **WHEN** 单条 deny 分别命中及未命中
- **THEN** false+非空规则解释代表命中，false+空解释代表未命中，条件诊断不得将两者混淆

### Requirement: 范围与失败边界
系统 MUST 保留严格 equals/in 和缺失/null 区分，决定性 scope 非法时拒绝；引擎或解释异常 SHALL 默认拒绝。执行阶段 SHALL 继续检查应用/资源实时状态与可信实体 scope。

#### Scenario: 实时停用与实体边界
- **WHEN** 首次 allow 后应用或资源停用，随后执行实体 guard
- **THEN** guard 拒绝；客户自报 attributes 不替代可信实体属性

### Requirement: 集合范围审计事件与幂等
系统 SHALL 保留原请求号及 scope 动作，对不同可信实体检查结果追加不可变事件；相同范围、可信属性及实时状态的同事件重试 MUST 幂等。审计事件身份 MUST NOT 替代实时应用/资源和实体范围复核，不保存可信属性明文。非 scope 审计 SHALL 保留原空事件键语义。

#### Scenario: 合法集合与重复复核
- **WHEN** 同请求的两个合法实体接受范围复核，并再次复核相同集合
- **THEN** 两次均成功；相同事件不重复写入或通知，审计仍可按原 request_id 与 scope 动作查询

#### Scenario: 合法后越权与重新成功
- **WHEN** 同请求先检查合法实体，随后检查越权实体并再次检查合法实体
- **THEN** 越权检查稳定返回403，拒绝与成功事件各自保留，拒绝不被重试或成功覆盖；失败清除守卫此前完成状态

#### Scenario: 旧结构或非事件约束失败
- **WHEN** event_key 迁移未执行、或审计写入触发其他存储约束错误
- **THEN** 系统失败，不回退原写入、不派生请求号且不吞错误；标准升级负责迁移及结构核对

### Requirement: 固定运行依赖发行
可安装载荷 SHALL 包含锁定实际运行依赖、模型与许可，运行验证 SHALL 记录官方 Enforcer 加载路径、版本和模型哈希。

#### Scenario: 缺失核心依赖
- **WHEN** Enforcer 不可加载或与锁定版本不符
- **THEN** 授权失败，不回退旧裁决器
