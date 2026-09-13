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
   真正查询数据库身份、更改数据或执行恢复，都必须另获当次授权；下面的身份查询本身只读，也不能成为
   创建数据库的授权。
3. 不比较 DSN、IP 或端口：这些都可能因 IPv4/IPv6、代理或连接池而不同，且仍指向同一 PostgreSQL
   集群/数据库。环境负责人必须预先批准完整的 `system_identifier:database_oid:database_name_hex` 恢复目标身份，
   并指定明确的非生产环境枚举。以下 guard 只读查询 `(pg_control_system()).system_identifier`、当前数据库在
   `pg_database` 中的 OID，以及非系统 schema 的用户关系数。查询无权限、返回空/多行、身份同一、目标不精确
   匹配、环境不是允许枚举，或恢复目标已有用户关系时，都在调用 `pg_restore` 前退出。source、target 和批准身份
   都必须是单行 ASCII `^[0-9]+:[0-9]+:[0-9a-f]+$`，因此 warning、空值、空白、额外字段或其他命令输出也会
   fail-closed；它不会创建数据库：

   ```sh restore-guard-contract
   set -eu

   : "${DATABASE_URL:?DATABASE_URL is required}"
   : "${RESTORE_DATABASE_URL:?RESTORE_DATABASE_URL is required}"
   : "${RESTORE_TARGET_IDENTITY:?pre-approved restore identity is required}"
   : "${RESTORE_TARGET_ENVIRONMENT:?explicit non-production environment is required}"

   identity_sql="SELECT (pg_control_system()).system_identifier::text || ':' || d.oid::text || ':' || encode(convert_to(current_database(), 'UTF8'), 'hex') FROM pg_catalog.pg_database AS d WHERE d.datname = current_database()"
   empty_target_sql="SELECT count(*)::text FROM pg_catalog.pg_class AS c JOIN pg_catalog.pg_namespace AS n ON n.oid = c.relnamespace WHERE n.nspname !~ '^pg_' AND n.nspname <> 'information_schema' AND c.relkind IN ('r', 'p', 'm', 'S', 'v', 'f')"

   identity_is_valid() {
     identity="$1"
     case "$identity" in *[!0-9a-f:]*|:*|*:) return 1 ;; esac
     previous_ifs="$IFS"; IFS=:
     set -- $identity
     IFS="$previous_ifs"
     [ "$#" -eq 3 ] || return 1
     system_identifier="$1"; database_oid="$2"; database_name_hex="$3"
     case "$system_identifier" in 0|0[0-9]*|'') return 1 ;; esac
     case "$database_oid" in 0|0[0-9]*|'') return 1 ;; esac
     decimal_not_greater_than "$system_identifier" 18446744073709551615 || return 1
     decimal_not_greater_than "$database_oid" 4294967295 || return 1
     [ "${#database_name_hex}" -ge 2 ] && [ "${#database_name_hex}" -le 126 ] || return 1
     [ $(( ${#database_name_hex} % 2 )) -eq 0 ] || return 1
     # Valid nonempty hex has a nonempty decoded byte sequence; no shell
     # arithmetic or lossy command substitution is used to parse identities.
     return 0
   }

   decimal_not_greater_than() {
     value="$1"; maximum="$2"
     case "$value" in *[!0-9]*|'') return 1 ;; esac
     [ "${#value}" -lt "${#maximum}" ] && return 0
     [ "${#value}" -gt "${#maximum}" ] && return 1
     [ "$value" \> "$maximum" ] && return 1
     return 0
   }

   source_identity="$(psql "$DATABASE_URL" -X -v ON_ERROR_STOP=1 -Atqc "$identity_sql")" || exit 1
   restore_identity="$(psql "$RESTORE_DATABASE_URL" -X -v ON_ERROR_STOP=1 -Atqc "$identity_sql")" || exit 1
   restore_user_relations="$(psql "$RESTORE_DATABASE_URL" -X -v ON_ERROR_STOP=1 -Atqc "$empty_target_sql")" || exit 1

   identity_is_valid "$source_identity" || exit 1
   identity_is_valid "$restore_identity" || exit 1
   identity_is_valid "$RESTORE_TARGET_IDENTITY" || exit 1
   source_identity_key="${source_identity%:*}"
   restore_identity_key="${restore_identity%:*}"
   [ "$source_identity_key" != "$restore_identity_key" ] || exit 1
   [ "$restore_identity" = "$RESTORE_TARGET_IDENTITY" ] || exit 1
   case "$RESTORE_TARGET_ENVIRONMENT" in development|test|staging|isolated) ;; *) exit 1 ;; esac
   [ "$restore_user_relations" = "0" ] || exit 1

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

## 维护者提示

发布维护者需要为候选包和恢复演练保留可独立复核的证据：候选 ZIP 与摘要、备份与恢复目标身份、
PostgreSQL 归档可读性、恢复后的关键对象/撤销状态、允许/拒绝/审计探针，以及清理记录。仓库内的离线
证据校验工具只校验证据结构；它不执行备份或恢复，也不能证明真实恢复成功。证据格式、发布门槛和维护者
验收机制不属于部署者的日常恢复操作，按项目维护流程另行处理。

数据库和密钥版本必须来自兼容时间点。若密钥不可恢复，应按事件响应流程轮换相关签名、加密、Webhook
和机器凭证，并强制失效旧会话。不要通过关闭验证或恢复旧明文绕过密钥不一致。
