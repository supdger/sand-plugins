# SandIAM 管理端插件

管理端页面位于 `src/views/plugin/sand-iam/`。

## 约定

- 任务看板：`docs/development/sand-iam-task-board.md`
- 协作：Cursor 独占本目录；Codex 独占 `plugin/sand-iam/`
- 未冻结 API 前：只保留页面壳与诚实空态，禁止猜测 DTO 或假 CRUD
- IAM-01 标「可消费」后：按契约接入真实管理接口

## 任务进度

- U-01：页面目录与占位页
- U-02：冻结 DTO 只读列表
- U-04：冻结写入面（save/update/disable、发布/撤销、凭证一次展示）
- 未完成：DETECT-01 监视；A-01 真实链路（依赖 signer 与登录会话）；SandAI SAND-113F 空白宿主双插件安装
