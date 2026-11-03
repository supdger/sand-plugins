<?php

declare(strict_types=1);

/**
 * update-0.2.7.pgsql 旧默认口令哈希处置的静态校验：
 * 从基线提交 b7f296b8 的种子 SQL 提取 sand_system_user 全部去重口令哈希，
 * 校验升级文件字面量、幂等特征、不改密码本身，以及与
 * InstallGuard::LEGACY_PASSWORD_HASHES / requiresPasswordChange 的一致性。
 */

use plugin\sandadmin\utils\InstallGuard;

$core = dirname(__DIR__) . '/server/plugin/sandadmin';
$worktree = dirname(__DIR__, 2);

require $core . '/utils/InstallGuard.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    fwrite(STDOUT, "PASS {$message}\n");
}

// ---- 从基线提交提取种子哈希（期望值） ----
$expected = [];
foreach (['sandadmin-6.0', 'sandadmin-pure'] as $name) {
    $path = 'sand-core/server/plugin/sandadmin/db/' . $name . '.pgsql';
    $content = shell_exec('git -C ' . escapeshellarg($worktree) . ' show b7f296b8:' . escapeshellarg($path));
    check(is_string($content) && $content !== '', 'git show b7f296b8:' . $path . ' succeeds');
    preg_match_all(
        '/INSERT INTO "sand_system_user" VALUES \(\d+, \'[^\']*\', \'([^\']*)\'/',
        $content,
        $matches
    );
    check($matches[1] !== [], $name . ' baseline seed users extracted');
    foreach ($matches[1] as $hash) {
        $expected[$hash] = true;
    }
}
$expected = array_keys($expected);
check(count($expected) === 4, 'baseline contains exactly 4 unique seed password hashes');
check(InstallGuard::requiresPasswordChange(false, '123456') === true, 'baseline hashes all verify as the legacy default password');

// ---- update-0.2.7.pgsql：字面量、幂等、不改密码本身 ----
$update = (string) file_get_contents($core . '/db/update-0.2.7.pgsql');
check(str_contains($update, 'ADD COLUMN IF NOT EXISTS "must_change_password"'), 'column add is idempotent (IF NOT EXISTS)');
check(preg_match('/UPDATE "sand_system_user"\s+SET "must_change_password" = 1\s+WHERE "password" IN \(/s', $update) === 1, 'legacy-hash accounts flagged by exact equality (idempotent UPDATE)');
check(preg_match('/SET\s+"password"\s*=/i', $update) !== 1, 'update never rewrites the password column itself');
check(stripos($update, 'password LIKE') === false && stripos($update, 'password ~') === false, 'no fuzzy password matching');
foreach ($expected as $hash) {
    check(str_contains($update, "'" . $hash . "'"), 'update SQL contains baseline hash ' . substr($hash, 7, 8));
}
check(substr_count($update, '$2y$10$') === count($expected), 'update SQL contains no hashes beyond the baseline set');

// ---- PHP 常量与 SQL 字面量、基线集合一致 ----
$constSet = InstallGuard::LEGACY_PASSWORD_HASHES;
$baselineSet = $expected;
sort($constSet);
sort($baselineSet);
check($constSet === $baselineSet, 'InstallGuard::LEGACY_PASSWORD_HASHES equals the deduplicated baseline seed hashes');

// ---- 判定边界：随机→1，自填→0，自填命中旧默认→1 ----
check(InstallGuard::requiresPasswordChange(true, 'correct horse battery') === true, 'generated password requires change');
check(InstallGuard::requiresPasswordChange(false, 'correct horse battery') === false, 'operator-chosen password does not require change');
check(InstallGuard::requiresPasswordChange(false, '123456') === true, 'chosen password matching a legacy default hash requires change');
check(InstallGuard::requiresPasswordChange(false, '1234567') === false, 'near-miss password does not match by fuzz');

// ---- provisionAdmin 传参形式：标志经绑定参数写入 ----
$controllerSource = (string) file_get_contents($core . '/app/controller/InstallController.php');
check(str_contains($controllerSource, '"must_change_password" = :must_change'), 'provisionAdmin binds the flag instead of hardcoding 1');
check(preg_match('/requiresPasswordChange\(\$passwordGenerated, \$adminPassword\)/', $controllerSource) === 1, 'install() derives the flag via InstallGuard::requiresPasswordChange');

fwrite(STDOUT, "update-0.2.7 legacy hashes: all checks passed\n");
