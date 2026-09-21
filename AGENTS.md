# AGENTS

先读 [README.md](README.md)。本仓只保留迁移记录、宿主同步和演示验收材料。

三个插件的源码修改必须在各自独立仓库进行：

- SandIAM：`supdger/sand-iam`
- SandWorkflow：`supdger/sand-workflow`
- SandAI 插件：`supdger/sand-ai`

禁止在本仓重新创建同名插件源码目录或发布插件 Release。消费宿主按
[宿主消费与同步](docs/host-consumer-sync.md)锁定 SandAdmin revision；实际数据库、服务和插件生命周期
操作仍需任务级授权。
