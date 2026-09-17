# 发布包校验

SandIAM 的正式候选交付应同时提供四个彼此独立的文件：

- `sand-iam-<version>-release-unsigned.zip`：完整安装包；
- `manifest.json`：逐文件 SHA-256、包摘要、源码 commit、`sand-iam/` tree object 与可复现构建结果；
- `sand-iam.release-attestation.json`：独立审核者对 ZIP 和 manifest 的 Ed25519 签名证明；
- `sand-iam-ed25519.pub`：通过项目官网、代码托管平台或另一条可信渠道公布的原始公钥。

只有 ZIP 没有签名证明，或签名证明附带了无法从可信渠道核对的公钥，都不能证明来源。
不要使用从尚未验证的 ZIP 中解出的脚本来验证同一个 ZIP；应从可信源码 revision、正式发布页
或组织内受控工具库分别取得并核对摘要的三份输入：`tools/verify-release-bundle.php`、
`tools/release-bundle-attestation.php` 和 `tools/package-payload-policy.php`。三者的可信来源
revision/摘要边界独立于待验 ZIP；少任一份、或只信任同下载目录/ZIP 内的副本，都不能构成验证基础。

## 校验命令

PHP 必须提供 `zip` 和 `sodium` 扩展。四个待校验文件应放在 SandIAM 源码目录之外：

```sh
php tools/verify-release-bundle.php \
  --artifact-manifest=/downloads/manifest.json \
  --archive=/downloads/sand-iam-0.7.3-release-unsigned.zip \
  --attestation=/downloads/sand-iam.release-attestation.json \
  --public-key=/trusted-keys/sand-iam-ed25519.pub
```

命令中的 `tools/...` 表示从可信源码 revision 取得的验证工具；`/downloads/...` 和
`/trusted-keys/...` 是发布时必须填写的参数占位，须替换为分别从独立可信渠道取得的候选文件和公钥。
它们不是安装包内路径，也不是任何固定机器路径。

验证器会同时检查：

1. 公钥、签名和证明文件的 schema；
2. 证明中的 manifest SHA-256 与本地 manifest 完全一致；
3. manifest v8 同时包含干净源码 commit、`sand-iam/` tree object 和 `release-build-contract.json` 摘要；它必须声明两套独立 Git-blob source stage 均已逐文件匹配该 commit，明确声明正常包排除历史 recovery descriptor，且签名证明完整覆盖这些来源与载荷身份；
4. ZIP 的文件名、字节数、SHA-256 和条目数与 manifest 完全一致；
5. ZIP 内每个文件的 SHA-256、大小、路径安全性以及无符号链接、无重复条目；
6. `LICENSE`、CycloneDX SBOM、第三方许可、安全与贡献说明齐全；
7. 安装包不包含测试和 spec 材料。

成功时命令以状态码 `0` 结束并输出 ZIP 摘要和条目数。任何文件被替换、增加字节、重打包，
或 manifest、签名、公钥不匹配时都会以非零状态码拒绝；不要绕过失败继续安装。

签名者如果在同目录 no-replace 发布的 `link`、权限或目录 fsync 阶段失败，会仅按自身记录的
dev/inode 清理 final/temp，并尽力 fsync 目录；不会删除同名的其他文件。非零退出时该输出名不能
作为发布物使用。特别是 `cannot safely clean failed attestation publication` 表示目录持久化结果
未能确认：隔离该目录，使用上述三份可信输入重新验证，并从新的候选重新生成证明。

## 公钥核对

公钥文件是严格 base64 编码的 32 字节 Ed25519 公钥。首次使用前，应从至少一条独立可信渠道
核对发布方公布的公钥指纹；更换公钥时应先确认轮换公告和生效版本。聊天消息、同一个下载目录
或压缩包内自带的未知公钥不构成独立可信来源。

校验通过只证明收到的字节与审核者签署的候选一致，不证明该候选已经在你的 SandAdmin、
PostgreSQL、身份提供方或业务系统中完成安装和兼容性验证。安装前仍应备份，并按
[安装与升级](installation-and-upgrade.md)在隔离环境完成对应版本的生命周期验证。
