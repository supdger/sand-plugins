-- SandAdmin Core 0.2.7：首次登录强制改密标志。
-- 幂等：可重复执行。升级已有部署时，在目标 PostgreSQL 数据库执行一次；
-- 未执行前，登录与鉴权按"无需改密"处理，不会影响现有账号。
ALTER TABLE "sand_system_user"
  ADD COLUMN IF NOT EXISTS "must_change_password" smallint NOT NULL DEFAULT 0;
