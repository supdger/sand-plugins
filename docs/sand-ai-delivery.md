# SandAI 交付同步

`sand-ai/plugin/sand-ai/` 是**交付产物**，不是源码。它的唯一源码在 SandAI 开发仓
`/Users/code/project/sand_ai` 的 `server/plugin/sand-ai/`，由脚本单向镜像进本仓，再随本仓发布到
GitHub `supdger/sand-ai`。

**禁止在本仓手改 `sand-ai/plugin/`。** 需要改插件后端时在 SandAI 开发仓改、提交，再执行交付。
`sand-ai/` 下的其他文件（`README.md`、`CHANGELOG.md`、`LICENSE`、生命周期 SQL、`tools/`、`tests/`
等发布与构建资产）由本仓维护，不在同步范围。

## 交付

```sh
scripts/deliver-sand-ai.sh            # 默认 dry-run：只列差异，不写入
scripts/deliver-sand-ai.sh --apply    # 镜像源码并重写交付记录
scripts/deliver-sand-ai.sh --check    # 只校验；不一致时非零退出
scripts/deliver-sand-ai.sh --apply --source /path/to/sand_ai   # 等价于 SAND_AI_SOURCE_ROOT
```

源码仓默认 `/Users/code/project/sand_ai`，可用 `--source` 或 `SAND_AI_SOURCE_ROOT` 指定；Git 程序可用
`SAND_GIT_BIN`（绝对路径）固定，与 `sync-plugin-to-demo.sh` 同一口径。

脚本行为：

- `server/plugin/sand-ai` 有未提交改动时拒绝交付（退出码 3）；交付记录必须对应源码仓真实 commit。
- Git 状态读取失败时失败关闭（退出码 6），空输出不解释成 clean。
- 按内容（`--checksum`）比对并 `--delete-delay` 删除目标多余文件，相同大小与 mtime 不能掩盖差异。
- 排除运行时与凭证形状的文件：`vendor/`、`node_modules/`、`runtime/`、`storage/`、`tmp/`、`.git`、
  `.idea/`、`.vscode/`、`.DS_Store`、`.env` 及 `.env.*`（`.env.example` 除外）、`*.key`、`*.pem`、
  `credentials.json`、`secrets/`、`*.log`。
- 不镜像权限位（`-rlt`，不带 `-p`；本机 openrsync 忽略 `--chmod`），`--apply` 后统一 `chmod -R go-w`
  去掉开发机带来的 group/other 写位。可执行位因此不随同步传递，当前载荷 235 个文件均非可执行；校验和只覆盖
  文件内容与相对路径。
- `--apply` 成功后写 `sand-ai/.delivery-source.json`：源码仓名、source commit、源子目录、目标子目录、
  生成时间、文件数和内容校验和。

内容校验和定义：对目标子树每个文件取 `sha256`，按相对路径排序拼成 `<sha256>  <相对路径>` 行，
再对整体取 `sha256`。实现只有一份，在 `scripts/check-staged-sand-ai-delivery.py`
（`--digest-tree <dir>` 可单独计算）。

## 提交门禁

暂存区涉及 `sand-ai/plugin/` 或 `sand-ai/.delivery-source.json` 时，pre-commit 运行
`scripts/check-staged-sand-ai-delivery.py`：

- 读**实际 Git index**（不是工作树），重算暂存载荷的校验和与文件数，与暂存的
  `.delivery-source.json` 对比，不一致或缺记录即拒绝，并提示改用交付脚本。
- 记录本身被篡改但树未变同样拒绝，因为两边必须同时自洽。
- `sand-ai/plugin/` 下出现 `sand-ai/plugin/sand-ai/` 以外的路径即拒绝。
- 未触及交付范围的提交不受影响。

安装：`bash scripts/install-pre-commit-checks.sh`（保留既有本地 hook；已安装的旧版生成 hook 原地升级，
不覆盖 `pre-commit.before-sand-checks`）。回归测试：
`/usr/bin/python3 scripts/check_staged_sand_ai_delivery_test.py`。

## 边界

源码镜像一致不等于构包、安装、升级、数据库或业务验收通过；那些仍是各自独立的关口。交付脚本不动数据库、
不启停服务、不提交、不推送。
