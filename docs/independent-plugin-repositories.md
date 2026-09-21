# 独立插件仓库迁移记录

迁移日期：2026-09-21

拆分来源为 `supdger/sand-plugins` 的干净提交
`21cd4d61c07beee401354e2b20483aa777db7e96`。本地未提交工作未进入公开仓库。

| 原目录 | 独立仓库 | 独立仓库初始提交 | 当前 Release | SHA-256 |
| --- | --- | --- | --- | --- |
| `sand-iam/` | `supdger/sand-iam` | `be4f5c8fd9140eef020852baaba4e90174bdd6ab` | `sand-iam-v0.7.3-preview-21cd4d6` | `a3648de10a47c1de263a24c5db5885c0199ab76efd28a1f41fcb7a99e92954ed` |
| `sandworkflow/` | `supdger/sand-workflow` | `87ff3c884b374ca961b0f7fa99b97bc4d6c0b27e` | `sandworkflow-v1.0.7-preview-216cccf` | `ffe6891ab7ee05763057e8bc4c4b04eaae75a0edc4618ea4afeaf80aaaee4b0a` |
| `sand-ai/` | `supdger/sand-ai` | `156094e8bc749f91cddb571160c7131ae261f9f2` | `sand-ai-v0.1.0-preview-216cccf` | `c670842a199813bfd440c183acff7c1b32fc9b6a37e3e15f4243eb925aa9d69d` |

三个 Release 均已从公开下载地址回读并核对摘要。SandAdmin 从
`9b0d59a6259615564d698e511e4ce14f218bb030` 起支持目录逐插件声明独立仓库。

迁移不改变已安装插件或数据库状态。旧本地工作区的未提交内容须分别审查后迁入对应仓库，
不能把本迁移记录当作那些改动已经发布的证据。
