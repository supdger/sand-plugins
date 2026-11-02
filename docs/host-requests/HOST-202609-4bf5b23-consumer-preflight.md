# SandPackage 持久存储消费预检

## 版本与执行边界

- 日期：2026-09-21。
- 只读核实 `/Users/code/project/sandadmin` HEAD 为 `4bf5b23bfc3f2712e7db8f3ab32d8b8393f02233`，工作树干净。
- demo 的 `sandadmin-host.lock` 仍为 `24258035e7f14d3131a420a9f91bd3526c400f62`；未更新版本锁，未实际同步、迁移、安装、卸载、建库或启停。
- 上游回归结果属于宿主证据，本记录只报告消费方实际完成的检查。

## 工具适配

新增 `scripts/sandpackage-storage-root.php`：加载被验宿主的自动加载器和 `PluginStorage`，显式传入被验 server/runtime 路径并调用 `root()`，不加载应用 bootstrap，不执行迁移。接口缺失及双根冲突均拒绝继续。

本机复用的 `.tmp/f07-verify-installed-candidate.php` 与 `.tmp/run-openapi-import-runtime.php` 已改用该入口；后者在任何生命周期请求前解析根目录，安装后重新解析，避免旧根清空后的根选择变化。这两个文件是本地验收材料，不是受版本控制的发布工具，后续归档或迁移工具时需一并携带入口依赖。

`run-sandpackage-reinstall*.php`、v36/v37 及 r09 各版本脚本绑定历史版本、固定包摘要或批次，不在本轮复用范围；故障复现夹具及旧路径文档作为历史证据保留。未执行上述业务验收脚本。

## 实测结果

- `php -l`：新增入口、F07 和 OpenAPI 工具通过。
- 临时夹具 `/tmp/sandpackage-storage-consumer-test.py` 使用冻结 revision 的实际 `PluginStorage`，验证空实例、旧根、新根、双根冲突、接口缺失，5/5 通过；逐场景文件快照不变，空实例不创建存储目录。
- 新旧同步保护此前已通过临时 source/target 的真实 rsync 测试：不复制源端持久数据、不覆盖同名目标文件、不删除目标独有数据，源端整个保护目录缺失仍保留目标，其他 storage 文件正常同步。
- 消费 dry-run：`PATH=/Library/Developer/CommandLineTools/usr/bin:$PATH zsh scripts/sync-sandadmin-host.sh --dry-run`，退出 0。原始输出 `/tmp/sandpackage-consumer-dry-run-4bf5b23.log`。
- dry-run 清单没有 `storage/sandpackage` 或 `runtime` 写入/删除项。逐项比较目标文件、原版本锁对应 Git 文件和当前发布源：所有内容变化为上游更新或新增；`config/autoload.php` 与 `vite.config.ts` 仅元数据差异；未发现文件内容分叉。
- 前端目录有 6 项待删除目录：`.tmp/`、`.tmp/r09v36m/`、其 `sandiam/`、`sandiam/outputs/`、`casdoor/`、`casdoor/mailbox-private/`。本轮未删除；这些本地临时目录的保留/清理决定尚未纳入同步授权。

## 后续真实验收

1. 确定上述本地临时目录处理方式，重新核实源 revision、工作树及 dry-run。获得实际同步授权后才 apply，并独立记录版本锁、部署文件与持久数据保留证据。
2. 在锁定版本的验收宿主只读检查实际登记根、列表与真实插件目录：正常登记、无登记 state 6、缺文件 state 7。临时夹具结果不替代此步骤。
3. 如需迁移，单独确认维护范围、全部写者停止及数据备份；先执行只读 inspect，检查冲突和未完成恢复，再依据明确授权执行维护迁移并验证数据原文、插件状态及重启后行为。
4. 安装/升级/卸载优先使用另行授权的可丢弃环境；不在本次通知下操作 demo 数据。
