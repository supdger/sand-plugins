# 测试

仓库不依赖 PHPUnit，以下为裸 PHP 脚本，在包根执行，退出码非 0 即失败。

```bash
php tests/frontend-publisher-test.php      # 前端发布：收养/拒绝、pending 重入、删除集、core 基线缺失降级、skip/force、符号链接
php tests/install-lifecycle-test.php       # 安装生命周期：目标路径解析与越界、前端失败不阻塞后端、config/ 仅首次写入、清单驱动卸载
php tests/dependency-command-test.php      # 依赖命令：COMPOSER_HOME 沙箱镜像注入、凭证继承、失败原因中文归类、锁冲突分类记号与输出摘录
php tests/uninstall-dependents-test.php    # 卸载依赖方检查：已安装依赖方列出与卸载拒绝、候选/其它依赖/配置缺失损坏不拦截、只读与锁释放
php tools/assert-publish-exclusions.php    # 发布排除规则四口径一致性门禁
node --test tests/frontend/dependency-chain.test.ts   # 一键安装依赖链判定：入队条件、结果状态（含 operation_pending 在途优先序）、结果未知错误识别与轮询收敛（Node ≥22.18 原生跑 .ts）
node --test tests/frontend/install-orchestrator.test.ts  # 依赖链编排器：安装中止/超时按快照裁决、停用窗口内轮询入队、在途等待与锁冲突瞬态重试、失败面板语义（Node ≥22.18 原生跑 .ts）
node --test tests/frontend/install-resume.test.ts        # 断点续跑：整页 reload 后按服务端行快照续跑（含事务在途轮询与撞锁重试）、断点记录读写清理、在途任务幂等匹配（Node ≥22.18 原生跑 .ts）
node --test tests/frontend/plain-confirmations.test.ts   # 普通确认对话框：撤回/登记确认串自动生成与服务端校验一致、卸载依赖方警告文案（Node ≥22.18 原生跑 .ts）
```

`install-lifecycle-test.php` 在子进程中逐个执行用例并分别采集 stdout/stderr，用临时目录模拟宿主，不触碰真实宿主，也不启动服务。

`tools/assert-publish-exclusions.php` 默认比对同级 `../sand-core/server/PublishExclusions.php`；该文件不存在时跳过并提示，用 `--no-cross-package` 可显式关闭跨包比对。
