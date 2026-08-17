# HOST-SYNC-01

- updated_at: 2026-08-14
- result: done
- scope: 将 Cursor 已完成的 SandIAM 管理端唯一源码
  `sand-iam/sandadmin-artd/src/views/plugin/sand-iam/` 同步至 SandAdmin 验收宿主
  `/Users/code/project/sandadmin/sandadmin-artd/src/views/plugin/sand-iam/`。
- reason: 复核发现宿主仍是 U-02 的旧副本，缺少 U-03/U-04 已完成的页面、写入交互与类型文件；源码包是权威来源。
- verification: 同步后两个目录无差异；验收宿主执行 `vue-tsc --noEmit` 与生产构建。
- boundary: 不变更 PHP、SQL、路由、权限码或运行时 signer；IAM-05 与 A-01 仍等待部署方配置真实 signer 和已有后台会话。
