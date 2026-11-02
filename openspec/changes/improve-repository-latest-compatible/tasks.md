## 1. 实现
- [x] 1.1 后端推荐身份及倒序，PHP行为测试覆盖乱序、无兼容、受阻和版本先后。
- [x] 1.2 卡片推荐及返回刷新，定向类型检查与行为测试验证加载失败/竞态。
## 2. 验收
- [x] 2.1 独立 Astra 审查固定候选并完成可用条件下界面验收，记录未运行边界。

验证记录：隔离候选 /private/tmp/sand-package-latest-compatible-20261003，基线 d335649。推荐/版本/HTTP重验证 13 项、宿主匹配 57 项、安装前置 57 项、native-state 回归通过；定向 vue-tsc 退出 0。前端交互/真实界面/独立验收未完成，不以类型检查替代。

2026-10-03 复验：独立 Astra 后端145项与隔离候选真实组件UI（mock API）通过；物理Windows推荐13、真实上传版本一致性18、宿主匹配57通过，临时资源清理完成。Windows浏览器、运行宿主根路由404、真实数据库安装未验收。用户允许复测并展示差异后合并；源码提交9ca2995，PR https://github.com/supdger/sand-package/pull/12 已创建，等待Windows CI通过后合并，未发布或同步宿主。

合并完成：PR #12 Windows CI run 37045625742 全部通过（33秒），2026-10-02T18:11:19Z 合入 main ab494988c7359b9b2ac14d2c3fdd1acb86fee9f2。GitHub API与git fetch远端main一致；feature 9ca2995是main祖先且内容diff为空。未创建Release/tag，未同步运行宿主。
