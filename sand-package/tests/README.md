# 测试

仓库不依赖 PHPUnit，以下为裸 PHP 脚本，在包根执行，退出码非 0 即失败。

```bash
php tests/frontend-publisher-test.php      # 前端发布：收养/拒绝、pending 重入、删除集、core 基线缺失降级、skip/force、符号链接
php tests/install-lifecycle-test.php       # 安装生命周期：目标路径解析与越界、前端失败不阻塞后端、config/ 仅首次写入、清单驱动卸载
php tools/assert-publish-exclusions.php    # 发布排除规则四口径一致性门禁
```

`install-lifecycle-test.php` 在子进程中逐个执行用例并分别采集 stdout/stderr，用临时目录模拟宿主，不触碰真实宿主，也不启动服务。

`tools/assert-publish-exclusions.php` 默认比对同级 `../sand-core/server/PublishExclusions.php`；该文件不存在时跳过并提示，用 `--no-cross-package` 可显式关闭跨包比对。
