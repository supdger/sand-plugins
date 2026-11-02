## Context
参见 proposal.md。公开 SandPackage main d335649 的 RepositoryLogic 与聚合权威副本一致。现有 action 在本地受阻时统一返回 manage，不能据此推导兼容性。

## Goals / Non-Goals
Goals: 管理员从插件仓库直接发现最高兼容版本，看到现有版本后手动选择升级。
Non-Goals: 自动升级、目录发布、同版本不同附件升级与宿主同步。

## Decisions
后端复用兼容判断，按版本倒序并返回 recommended_version 身份。PHP version_compare 会将 preview 标签排在正式版之后的错误方向，因此仅插件版本采用三段核心加预发布标识的最小比较器，推荐与升级判定共用；宿主兼容不改变。catalog HTTP 请求单独使用 no-cache 重验证，发布附件维持原缓存策略。前端只按身份取版本，避免重复比较算法；旧接口缺少字段时不冒称最新兼容，提示刷新或更新安装器。卡片显示“最新兼容版本”“升级到 v…”；详情保留全部版本和tag/说明。每次tab进入和KeepAlive重新激活刷新，首次激活避免重复请求；保留已有requestId竞态保护，刷新时清空旧目录和弹窗对象，写入前原有重新验证保持。

## Risks / Trade-offs
目录未发布新包时页面只能展示目录实际内容；不会从GitHub Release猜测替换清单。预发布从已有版本/tag表达，不新增策略切换。前后端必须同版本发布；此轮仅源码候选。
