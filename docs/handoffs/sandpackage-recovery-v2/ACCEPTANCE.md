# SandPackage recovery v2 双方验收状态

当前总状态：`HOST_COMPATIBLE / DEMO_SYNC_6_1_4_STATIC_PASS / FORMAL_GATE_A_PASS / RUNTIME_RECOVERY_NOT_AUTHORIZED`。

| 门 | 状态 | 证据 / 限制 |
| --- | --- | --- |
| 宿主提交身份 | PASS | SandPackage 6.1.4，`a99af5d`，parent `d29d6f3`；7-file source change set 已核对 |
| 三文件生产增量 | DEMO_SYNC_6_1_4_STATIC_PASS | byte sync 3/3、关键 SHA 5/5、demo lifecycle 10/10、lint 3/3、vue-tsc PASS；digest `dbdec…5f34f` |
| demo PHP / class load | PASS | PHP lint 3/3；PSR-4/class load PASS |
| demo lifecycle non-DB | PASS | 10/10；无 DB 连接 |
| source contracts | PASS | contracts 7/7；neutral 38/38；recovery subphases 17/17；lifecycle 4/4；fixture/fake-PDO |
| formal identity-bound Gate A | **PASS** | replacement `8ecc5cec…f9e9f`：101/101、`failed_ids=[]`、`audit_written=false`、非空 fingerprint；连接已复用并回滚 |
| replace / retry / runtime | NOT STARTED | 未改 registry 或运行文件 |

阶段完成度：同步字节门 **3/3 PASS**；demo non-DB lifecycle 门 **10/10 PASS**；真实恢复运行阶段
为 **1/5**（仅 formal Gate A）。不得以该只读门代替 replace/retry、业务恢复或部署验收。

P2 historical risk：先前 `DEMO_SYNC_6_1_4_STATIC_REJECT` 来自一次不可复现 TerminalRunner 聚合失败且
harness 未保留 failure frames；复跑已通过，不阻断静态门。

本页不授权数据库写、active registry mutation、prepare、额外 Gate A、replace、retry、服务、浏览器、提交、推送或部署。
