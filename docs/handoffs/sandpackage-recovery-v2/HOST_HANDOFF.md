# SandPackage 6.1.4 / recovery v2 宿主交接

当前状态：`HOST_COMPATIBLE / DEMO_SYNC_6_1_4_STATIC_PASS / FORMAL_GATE_A_PASS / RUNTIME_RECOVERY_NOT_AUTHORIZED`。
本页只记录受控 demo 同步与非写入验证；不等同于真实恢复完成。

## 当前 6.1.4 冻结身份与三文件增量

- SandAdmin revision：`a99af5dea0f22071939a9010a958e90829c675a3`
- parent：`d29d6f390d89c6cbf3d3e1cb6ee77cde0ade0bde`
- SandPackage：`6.1.4`
- 3-file digest：`dbdec85c3558592f2af196a689a28fdaa89f9ce56e8e38ecfcf93e6f3de5f34f`
- 证据：`.artifacts/sandpackage-614-demo-sync-20260908T151341Z/`

已只读核对 7-file 源提交集；demo 仅同步以下三个生产文件。所有目标前像均存在并已保存，
source/target SHA 3/3 一致，白名单外 SandPackage 文件摘要不变。

| 路径 | SHA-256 |
| --- | --- |
| `server/plugin/sandpackage/app/logic/InstallLogic.php` | `57d833d07a54ae29c4b9010629aa1f930cb42a4d7083289b56d6b91449d5970c` |
| `server/plugin/sandpackage/app/service/PostgresLifecycleSqlExecutor.php` | `c02a8b039dc9246ac2d38b25e535631d5889a3f9b175ef14f702e4f6dfd5343a` |
| `server/plugin/sandpackage/config/app.php` | `5271276a32111a6fca756a94ca1d78575626989ca7bac39e077b461362e22bd1` |

`source-3-sha256-lines.sorted.txt` 与 target 对应文件采用 C-sorted conventional
`sha256␠␠relative-path` LF 行；摘要均为 `dbdec…5f34f`。当前同步门为
**`DEMO_SYNC_6_1_4_STATIC_PASS`**（byte sync 3/3、demo lifecycle 10/10、lint 3/3、vue-tsc 与关键 SHA 5/5 均通过）。

## 6.1.4 修复与验证边界

`InstallLogic` 的 recovery read-only connection 改为显式调用 named PostgreSQL connection 的
`connect()`；`PostgresLifecycleSqlExecutor` 在没有注入 PDO 时也使用 `connect()`，避免把未初始化的
`getPdo()` 当作可用连接。

- demo PHP lint：3/3；PSR-4/class load：PASS。
- demo `lifecycle_non_db_contract_test.php`：10/10 PASS；demo `vue-tsc --noEmit`：PASS。
- source SandPackage contracts：7/7 PASS；neutral payload 38/38；recovery subphases 17/17；
  lifecycle 4/4。均为 fixture/fake-PDO 合同验证。

已有 private prepared replacement，标识仅记录为 `present/redacted`，本任务不披露或操作该 ID。
6.1.4 未执行 Gate A 重跑；formal Gate A 为 **`PREPARED_REPLACEMENT_PENDING_RERUN`**，绝非 PASS。
未执行数据库/registry、prepare、Gate A、replace、retry、运行恢复、服务、浏览器、提交、推送或部署；
未修改 SandIAM candidate payload，也未扩大三文件白名单。

P2 已知风险：先前 `DEMO_SYNC_6_1_4_STATIC_REJECT` 来自一次不可复现的 TerminalRunner 聚合失败；
harness 未保留 failure frames。后续 source/demo 连续复跑均通过，故归档为历史瞬态，不阻断本静态门。

## 6.1.4 formal Gate A PASS

授权的 Gate A 仅使用既有 replacement `8ecc5cec83f03f19ea609b220e8f9e9f`，未 prepare。返回
`retry_safe`、101/101、空 failed assertion ids、`audit_written=false` 和非空 fingerprint
`714894c6…a16ce6`。`InstallLogic` 仅在 reusable connection 后返回，verifier 的事务顺序为
`BEGIN READ ONLY`、`SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY`、`ROLLBACK`。
active registry 未变；replace/retry、数据库写、运行恢复、服务、浏览器、提交和推送均未执行。
当前 formal Gate A 为 **`PASS`**，后续 replace/retry 仍未获授权。
