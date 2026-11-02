## Context

现行 PolicyAuthorizer 循环匹配身份/有效角色、equals/in、priority/effect，并逐条查版本。应用服务先认证和解析 API，执行实体阶段再次检查 application/resource。参考经独立复核的 plan-v2，事实源仍是现有表，无新策略表。

## Goals / Non-Goals

**Goals:** 官方 Enforcer 唯一判定，薄只读转译现有已发布事实，运行和模拟共用模型，固定发行依赖。

**Non-Goals:** 不改机器凭据/grant、前端、数据库 schema，不引入任意 eval、跨请求缓存或公开 Casbin CSV 配置。

## Decisions

- 精确 pin 官方稳定包，Composer platform 设 PHP 8.2.0，避免本机 8.4 解析出的依赖破坏插件 >=8.2 契约。依赖全部进现有 plugin/vendor 载荷契约。
- 固定模型 r=sub,dom,obj,act,attrs；p=priority,sub,dom,obj,act,cond,eft,pid,vid；g=_,_,_；priority(p.eft)||deny。按原 priority、deny先、policy.id 排序赋唯一 rank。排序不看请求、不筛命中，匹配/冲突全由 Casbin。唯一扩展只读取规范 JSON condition，检查形状与缺键，并调用官方 Symfony ExpressionLanguage 固定 value === expected / value in expected。
- 从 ThinkORM 当前主连接 getPdo() 取得 PDO，在首条查询前开启 REPEATABLE READ READ ONLY，所有查询直接在同 PDO 完成。外层事务无法升级或证明为新事实快照时 fail closed，不套 savepoint 冒充新快照；公开运行入口均在认证事务结束后调用。加载完成立即 commit，audit 在事务外。
- 快照批量加载应用/身份/资源/策略版本/引用归属/关系/API及动作状态。p 只取 status=1+发布指针，不新增state筛选。丢版本、非法主体/effect/condition/跨应用引用整应用拒绝；停用关系仅不生成 g。
- Adapter 只加载 p/g，AutoSave关闭，全部写方法抛异常。simulate 的每条目标规则用同模型、同g、原effect的单p Enforcer.enforceEx；最终独立读取全集 Enforcer结果。
- 发布服务在创建版本前调用同一无请求映射校验，旧非法scope仍在决定性规则选中后拒绝，不把scope放进matcher跳过。

## Risks / Trade-offs

- [既有外层事务调用] → 当前公共调用和真实测试核查；无法保证新快照时显式拒绝，无混合读。
- [历史损坏配置] → 返回拒绝并审计policy/version，不转public、不静默过滤。
- [请求内构建成本] → 批量版本查询、仅请求内Enforcer；采样真实成本，不引用其他语言benchmark。
- [宿主已有Symfony包先加载] → 锁定PHP8.2依赖，在现有宿主autoload上下文实跑，同时报告载荷与已安装环境证据差别。

## Migration Plan

源码接入和本地基线/PG回归后冻结依赖契约与包内加载验证；不隐式同步宿主。实际安装、多Worker业务验收待受控宿主阶段。回退仅显式恢复前一已验证包，禁止自动fallback或还原策略事实。

## 跨公共打包规则变更论证

实际审查包发现现有通用过滤器按目录段名排除 cache/log，误排官方依赖的合法运行类：vendor/psr/cache/src/CacheItemPoolInterface.php、vendor/psr/log/src/LoggerInterface.php、vendor/symfony/cache/Adapter/ArrayAdapter.php、vendor/casbin/casbin/src/Log/Logger/DefaultLogger.php。它们是Casbin真实运行依赖，不是应用生成缓存或日志；直接消费公开Enforcer必需且不能通过宿主私有vendor补齐。替代方案删除cache/log规则或整个vendor绕过会放宽敏感/临时文件排除，故采用精确四个已锁定runtime前缀的段名保护，后代tests、.env、私钥、日志、缓存和临时目录仍经过原排除规则。

generated_payloads 应冻结实际发行文件，不能把已明确排除的官方vendor/tests纳入发行树哈希；contract checker对有效文件按同payload规则计算，独立Git builder本已按ZIP条目计算。该变更限SandIAM自己的工具，不跨修改SandCore/SandPackage/其他插件；它修复通用载荷分类错误，不搬入调用方业务。实施前独立评审TMP最小候选，后验证正确runtime路径保留、秘密/缓存/tests仍排除和真实ZIP加载。


独立共享规则审查通过：精确 runtime 前缀只解除合法组件路径那一段，.env 与 .env.*、私钥、tests、后代缓存/日志/临时路径仍拒绝，88/88 边界与 lint 通过。有效发行 vendor 389 文件，tree SHA256 b2c84a0d19678bd5b75f6f233fb67d9a720d0885282b8cfd732f602e48d144c8。

## 旧能力去留与官方替代


| 旧生产能力 | 官方替代 | 生产去留 |
| --- | --- | --- |
| PolicyAuthorizer::belongsToSubject / snapshotTargets / 匹配循环 | 固定Casbin matcher 的 sub/dom/obj/act、g、条件桥接 | 方法和循环已从生产文件删除；旧源码仅TMP冻结基线，不提交、不维护 |
| PolicyAuthorizer::effectiveRoles / selectLowestPriorityRules / deny优先循环 | 官方DefaultRoleManager domain/g；priority(p.eft)||deny | 已删除旧关系匹配与优先级/效果裁决；SnapshotLoader仅SQL读取现有边，Adapter只生成g和来源展示 |
| simulate自行筛选优先级和效果 | 全集Enforcer.enforceEx+逐p同模型Enforcer.enforceEx | 已删除旧计算；补buildRoleLinks使单p也有官方g图；不复制角色算法 |
| Policy condition 的ScopeMatcher::matches strict comparison | 官方Symfony ExpressionLanguage固定value===expected / value in expected | 已从权限condition生产路径移除旧primitive调用；仅保留JSON解析、已有形状验证、缺键array_key_exists和固定表达式选择，无客户端表达式/eval |
| 发布/只读加载写权威 | Casbin Persist\Adapter接口+AutoSave false | 薄Adapter所有write拒绝，既有PolicyVersionService继续管理不可变版本与审计，不平行建表 |
| 返回scope后的业务实体落实 | 现有ScopeMatcher::matches + assertScope实时应用/资源状态 | 保留：是业务数据义务，用户未要求替换；Casbin不能替代业务过滤/可信实体检查 |

`PolicyAuthorizer` 文件保留的仅是已有公开PHP门面（authorize/simulate/assertScope）及审计：`docs/development/sand-iam-authorization-contract.md` 第8/25行明确让业务应用调用它，内部API服务和管理simulate也调用。保留入口避免无意义破坏调用契约；文件不再包含主体/角色/优先级/效果匹配代码，所有策略决定来自官方Casbin。它不是旧引擎fallback。类名重命名无法增加官方复用，反而破坏现公开PHP契约，因此不删除该薄入口。

没有RoleResolver生产类或旧PolicyEngine副本；角色事实读取、类型/域命名、来源展示是适配既有表所需。基线测试只保留独立预期和事实夹具，不保存/复制旧判定源码。此边界不删除其他任务未知文件、业务scope、凭据或机器grant。

必要性实证：Symfony7.4.18 `in`已严格区分string/int，`===`原生支持，故原语交由它执行。直接原生array访问对缺键和显式null都返回null==null true（缺键另warning），不满足现有缺键必须false；官方Casbin builtin无现JSONB clause解释。桥接只处理presence/形状/已固定DSL的动态key，不重做native比较。

独立审查四项返工：单p诊断显式 buildRoleLinks；snapshot reserved metadata 整体拒绝，权威 policy/version 字段不可覆盖；不存在应用审计使用 null 外键，正常 deny；条件原语全部官方 ExpressionLanguage，旧实现仅 TMP 行为基线而不交付。
## 0.8.0 实机集合范围审计修复

真实已安装候选 `93a4a1e78ac8900255c7639cffbe4a408b6845fa` 在同请求集合复核时第二次 `scope.read` 插入撞 `(request_id, action)`；合法集合变成 SQLSTATE 23505，合法后越权也丢失预期 403。修复保持 Casbin 与业务 scope 判定不变，不把 scope 审计键用作权限缓存。

审计仍按事件不可变追加。`sand_iam_audit_log.event_key` 为 `varchar(64)`，默认空字符串；唯一键扩为 `(request_id, action, event_key)`，原非 scope 写入继续空键语义。scope 键为 SHA-256：带格式版本的 actor/identity、客户主体、应用、资源、operation、outcome、实时应用/资源可用状态、scope 与可信实体属性的规范 JSON；对象键排序，列表顺序与值类型保留。调用方解析器提供的相同可信属性、范围与状态视为相同检查；需要区分相同归属的不同实体时解析器同时提供可信实体标识。审计仅增加 fingerprint，不追加实体属性明文；摘要用于去重与关联，不承诺匿名化。

scope 插入使用 writer 连接单语句 `ON CONFLICT (request_id, action, event_key) DO NOTHING RETURNING id`；相同事件重试不追加或重复发布通知/告警，其他数据库错误不吞。新 scope 分支沿用同连接 ThinkORM 事务，已有 ORM 事务使用 PostgreSQL savepoint，审计与 outbox 原子提交；outbox 失败回滚本次事件，重试仍能再次发布。best-effort 告警另用嵌套 savepoint，告警 SQL 失败不会让外层审计/outbox 事务留在 aborted 状态。非 scope 原写入事务契约不变。不同可信属性、拒绝结果或实时状态生成不同事件，始终保留原 request_id 与 action。首次成功后后续拒绝不覆盖成功，更不会被后续成功覆盖。每次守卫复核先清除完成状态，失败不能沿用上次完成。

旧结构缺 event_key 必须通过标准升级后运行，缺字段/约束时失败，不回退原写入或更改请求号绕过。迁移、结构指纹及 0.8.0 生命周期由对应源码生成；只编写迁移不表示已经执行数据库升级。最小非 PostgreSQL 回归贯穿生产 `ApplicationAuthorizationService → EntityScopeGuard → PolicyAuthorizer → AuditWriter`，覆盖合法集合、相同重试、部分拒绝、原请求关联、状态撤销、脱敏与错误透传；真实 PostgreSQL 和新候选宿主升级为独立验收门槛。
## 关联实机验收缺陷：SAML ACS URL 环境适配

此项仅记录同候选实机验收发现的联合身份缺陷，不属于 Casbin 策略引擎职责。当前变更目录未发现 SAML 专属 change，沿用既有联合身份实现及验收入口，不复制 SAML 验证器。

实际 Webman TLS HTTP 回调的 `$_SERVER` 仍为 CLI 进程环境（URL 键缺失、SCRIPT_NAME 为 start.php）；OneLogin 4.3.2 `Response::isValid` 通过 `Utils::getSelfRoutedURLNoQuery` 重构错误 URL，拒绝有效签名响应。Settings.baseurl 会在 Response 构造时改写全局 Utils URL 覆盖项，且不能单独提供精确 routed ACS；其 getters 返回有效值而非原始 null 覆盖状态，因此不能作为可精确恢复的每请求配置使用。

最小适配保持官方 `Response::isValid` 及现有精确 Destination/Recipient 绑定检查。可信 ACS 来自 `FederationService::startSaml` 的已解密身份源 `acs_url`，经 HTTPS 校验写入事务 `redirect_uri`；`completeSaml` 验证身份源配置版本后传入该服务端事务值。请求体和转发头都不能决定它。verifier 在官方 `isValid` 的同步作用域临时设置仅该可信 ACS 的 CGI URL 键、移除 forwarded URL 键，并在 finally 恢复每个键的原存在性和值；不调用 OneLogin 静态 URL setter。进入官方校验前，官方 routed/non-routed URL 必须都等于可信 ACS；既存静态覆盖冲突时失败，不清除他人的状态或放宽校验。

同步作用域限定为锁定库中的 DOM/XPath、包内 XSD 和 OpenSSL 校验，不含数据库、HTTP 客户端、用户回调或 coroutine/yield 操作。Response 的 schema 路径来自本地 Settings；protocol→assertion/XMLDSig→xenc 依赖均为包内相对 XSD，XMLSecLibs Reference 只取当前文档，RetrievalMethod 仅接受内部 `#` 引用，证书以 `loadKey($cert, false, true)` 字符串读取。当前 Webman worker 的 PHP 进程环境彼此独立；Fiber 用例证实官方调用返回前不会挂起。此结论限当前锁定库及运行入口，不作为未来添加异步操作后仍可修改共享环境的保证。

定向回归使用真实 OneLogin/XMLSecLibs 生成双签名响应，覆盖缺失 Webman CGI 环境、恶意转发头、不同租户 host/9447 端口/path、错误证书/关联请求/受众/目的地/Recipient 前缀、XML 失败、既存 Utils 覆盖冲突、成功与失败后的 server/Utils 恢复和 Fiber 调用。原有时效、Issuer、重复断言包装、签名 AuthnRequest 与服务端 replay 入口回归保留。真实 HTTP ACS 复测及安全独立审查另行记录。
