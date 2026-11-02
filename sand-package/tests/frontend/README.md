# 前端行为夹具

`failed-upgrade-recovery.behavior.ts` 是 `sandadmin-artd` 失败升级恢复页的浏览器行为检查，属于开发夹具，不随发布载荷分发（`tests/` 已在 `.gitattributes` 与 `composer.json archive.exclude` 中排除）。

当前它依赖的 9 个同级夹具（`*.http-mock.ts`、`*.index-mount.ts`、`*.directives-mock.ts`、`*.vite.config.mts`、`*.tsconfig.json`、`*.viewport.html` 等）已在上游从仓库移除，本文件暂不可独立运行；恢复运行需把这些夹具重新放在本目录下。
