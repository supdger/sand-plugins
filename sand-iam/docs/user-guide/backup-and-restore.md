# 备份与恢复

## 备份范围

同一时间点保存 PostgreSQL 数据库、SandIAM 插件文件、管理端产物、门户产物、部署配置、
密钥版本标识和候选包摘要。秘密应由密钥系统单独备份，不写入普通归档。

数据库备份至少覆盖所有 `sand_iam_*` 表及其序列、约束和迁移账本。若采用整库备份，
恢复演练必须同时确认宿主及其他插件不受损。

## 生成并校验 PostgreSQL 归档

以下命令不会创建数据库；它们要求 `DATABASE_URL` 已由受控运行环境注入。不要把含密码的 DSN
写进脚本、终端历史或归档文件名。整库 custom-format 归档能同时保留 SandIAM 与宿主的一致时间点：

```sh
umask 077
BACKUP_FILE="sandadmin-$(date -u +%Y%m%dT%H%M%SZ).dump"
pg_dump --format=custom --no-owner --no-acl --file="$BACKUP_FILE" "$DATABASE_URL"
pg_restore --list "$BACKUP_FILE" >"$BACKUP_FILE.list"
sha256sum "$BACKUP_FILE" "$BACKUP_FILE.list"
```

保存命令退出状态、PostgreSQL/SandAdmin/SandPackage/SandIAM 版本、两个 SHA-256 和备份时间。
`pg_dump`、`pg_restore --list` 或摘要计算任一失败，就不能把文件登记为可恢复备份。列表中应能找到
`sand_iam_*` 表、相关序列/约束和 SandIAM 迁移账本；列表校验只证明归档可读取，不能代替恢复演练。

## 恢复演练

1. 在隔离环境锁定与备份匹配的 SandAdmin、SandPackage 和 SandIAM 版本。
2. 由环境负责人预先提供空的隔离 PostgreSQL 数据库并注入 `RESTORE_DATABASE_URL`。本流程不创建数据库，
   不允许把 `RESTORE_DATABASE_URL` 指向生产库，也不得使用 `--create`、`--clean` 或自动建库选项。
3. 先确认源库与恢复库不是同一 DSN，再以单事务恢复；任何对象冲突或 SQL 错误都必须整体失败：

   ```sh
   test -n "$DATABASE_URL" && test -n "$RESTORE_DATABASE_URL"
   test "$DATABASE_URL" != "$RESTORE_DATABASE_URL"
   pg_restore --exit-on-error --single-transaction --no-owner --no-acl \
     --dbname="$RESTORE_DATABASE_URL" "$BACKUP_FILE"
   ```

4. 恢复与备份匹配的插件文件、管理端/门户产物和配置，重新关联正确的密钥版本。不要从 demo
   或恢复环境反向覆盖权威源码。
5. 检查迁移账本、组织/应用/环境层级、用户与机器身份、策略、凭证状态和审计连续性。
6. 分别验证允许、拒绝、撤销后拒绝、Webhook 幂等、同步游标和协议登录。
7. 确认恢复前已经撤销的会话和凭证不会复活，恢复后的新审计可以继续写入并关联 request id。

恢复演练不得覆盖生产数据库。演练结束后按隔离环境的授权流程清理，不删除需要保留的审计证据。
只有恢复命令成功、关键对象与撤销状态一致、允许/拒绝/审计链通过，并确认宿主及其他插件未受损，
才能把该备份标记为“已验证可恢复”。

## 恢复点与密钥

数据库和密钥版本必须来自兼容时间点。若密钥不可恢复，应按事件响应流程轮换相关签名、加密、Webhook
和机器凭证，并强制失效旧会话。不要通过关闭验证或恢复旧明文绕过密钥不一致。
