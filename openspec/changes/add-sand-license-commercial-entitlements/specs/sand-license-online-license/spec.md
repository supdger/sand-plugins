## Purpose

为桌面软件提供手工发码、首次兑换起算、安装持有证明、可配置席位和短期在线租约。使用者能够确认期限与设备状态，并在释放或人工重置后安全恢复，旧租约不可导致同一席位同时被新旧设备占用。

## ADDED Requirements

### Requirement: 许可重试不绕当前授权
系统 MUST 在许可敏感幂等结果返回前验证当前权益及安装状态；current不复用历史active/features，必须使用新有效一次challenge与proof读取当前事实，已释放、撤权或到期不能因历史成功结果继续授权。

#### Scenario: 成功请求后撤权再重试
- **WHEN** 一个成功current或renew请求在权益撤销或安装释放后以相同request_id再次调用
- **THEN** 拒绝授权，不返回历史active/features或新的租约。

### Requirement: 换机资格重发有安装主体边界
系统 MUST 在撤销旧票前核对安装重发授权：释放安装仅能重签source_activation_id等于自身的未消费换机票，额外团队票的空source需要活动安装；重置发票参数仅接受JSON布尔，默认false，并进入幂等业务摘要。

#### Scenario: 释放安装恢复额外团队资格
- **WHEN** 已释放安装试图重签source_activation_id为空的额外团队票
- **THEN** 拒绝且不撤销旧票，不返回新秘密。

### Requirement: 首兑起算与默认席位
系统 SHALL 在首个成功兑换中原子创建一次权益，默认seat_limit为1并允许套餐配置团队席位；期限从该UTC兑换时间起算，按月/年采用目标月末截断。

#### Scenario: 月末首次兑换
- **WHEN** 一个月套餐于UTC1月31日首次兑换
- **THEN** 到期时间为对应2月最后一日同一时分秒；重复请求不再创建权益或改变起算点。

#### Scenario: 并发首兑和席位竞争
- **WHEN** 不同安装同时兑换同一码或争夺最后席位
- **THEN** 仅一个允许的状态迁移成功，不重复发权益且席位不超套餐限制。

#### Scenario: 领取来源变更后的旧码首兑
- **WHEN** 领取关联码在退款、chargeback或明确重发后提交兑换
- **THEN** 在产品→来源→claim→code行锁后重新核验来源paid和完整关联，拒绝终态来源或非当前码；不能依据无锁hint或历史成功缓存再次发权益。

### Requirement: 安装证明不能重放
系统 MUST 使用短时一次challenge和安装私钥持有证明绑定请求方法、目标、产品与请求摘要，拒绝重放、过期和安装不匹配。安装协议（包括当前权益读取）统一POST JSON，完整activation_id/product_code/challenge/request_id参数受原始body SHA256签名绑定；nonce不进入URL query，不提供旧GET兼容。

#### Scenario: 证明复用或内容替换
- **WHEN** 相同nonce/jti被再次使用或证明对应另一产品/请求内容
- **THEN** 拒绝请求且不发权益、安装或租约。

#### Scenario: 同安装钥多权益的选择器替换
- **WHEN** 同一安装钥用于同产品多个权益，客户端证明签了一个activation_id但原始JSON body被替换成另一个
- **THEN** 因body摘要不匹配拒绝，不能用未签名的URL选择器改变目标权益；旧GET current不提供许可结果。

### Requirement: 强制在线启动与短期租约
系统 SHALL 签发Ed25519租约，最长15分钟且不超过权益截止，建议每5分钟续签；新启动必须取得新在线租约，断网当前运行不得超过exp。

#### Scenario: 启动仅持缓存租约
- **WHEN** 软件新启动但不能取得在线新租约
- **THEN** 不允许开始许可功能，即使缓存签名尚有效。

#### Scenario: 续签失败或撤权
- **WHEN** 网络断开或权益/安装撤权导致续签拒绝
- **THEN** 当前运行最晚在已签租约exp停止，在线敏感调用立即按当前领域状态拒绝。

### Requirement: 释放后的席位保护
系统 SHALL 支持安装证明自助释放及有权人工重置，立即禁止旧安装续签，席位等待该安装最后已签租约到期后才可再占用。

#### Scenario: 释放与续签并发
- **WHEN** release/reset与renew同时处理
- **THEN** 状态和租约按事务统一判定，已释放安装不能续签，新安装不能在返回seat_available_time前占原席位。

### Requirement: 新设备资格不重复发权益
系统 SHALL 允许已授权安装或管理员为现有权益签发一次性新设备资格，仅摘要持久保存；新设备消费资格时遵守席位与旧租约保护，原已兑卡密不得用于日常续签或再次发权益。

#### Scenario: 团队加设备或释放后换设备
- **WHEN** 新安装提交有效资格与持有证明
- **THEN** 仅在资格生效且席位可用时增加一个安装，资格只能消费一次，原权益起止时间和grant不变。
