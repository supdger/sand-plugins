<?php

declare(strict_types=1);

// behavior-test-gate: static-rule
// Loads frozen SandPackage classes. Dynamic work uses only /tmp; source
// slicing is a static rule for host-bound public entry points.

namespace plugin\sandadmin\exception { if (!class_exists(ApiException::class)) { class ApiException extends \RuntimeException {} } }
namespace Saithink\Saipackage\service {
    if (!class_exists(Server::class)) {
        final class Server { public static function getIni(string $directory): array { $file = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'info.ini'; $ini = is_file($file) ? parse_ini_file($file, false, INI_SCANNER_TYPED) : false; return is_array($ini) ? $ini : []; } }
        final class Version {} final class Filesystem {} final class Depends {}
    }
}
namespace plugin\sandadmin\app\cache { if (!class_exists(UserMenuCache::class)) { final class UserMenuCache {} } }
namespace plugin\sandpackage\app\service { if (!class_exists(PostgresLifecycleSqlExecutor::class)) { final class PostgresLifecycleSqlExecutor {} } }
namespace support { if (!class_exists(Log::class)) { final class Log { public static function info(string $message, array $context = []): void {} } } }
namespace think\facade { if (!class_exists(Db::class)) { final class Db {} } }

namespace {
    use plugin\sandpackage\app\logic\FailedUpgradePackageIdentity;
    use plugin\sandpackage\app\logic\FailedUpgradeRecoveryFileTransaction;
    use plugin\sandpackage\app\logic\FailedUpgradeRecoveryInspector;
    use plugin\sandpackage\app\logic\FailedUpgradeRecoveryVerifier;
    use plugin\sandpackage\app\logic\InstallLogic;

    $root = sys_get_temp_dir() . '/host-202609-001-' . bin2hex(random_bytes(8));
    if (!function_exists('runtime_path')) { function runtime_path(): string { global $root; return $root . '/runtime'; } }
    require_once __DIR__ . '/../../../../sandadmin-demo/server/plugin/sandpackage/app/logic/FailedUpgradeIdentityBinding.php';
    require_once __DIR__ . '/../../../../sandadmin-demo/server/plugin/sandpackage/app/logic/FailedUpgradeRecoveryVerifier.php';
    require_once __DIR__ . '/../../../../sandadmin-demo/server/plugin/sandpackage/app/logic/FailedUpgradePackageIdentity.php';
    require_once __DIR__ . '/../../../../sandadmin-demo/server/plugin/sandpackage/app/logic/FailedUpgradeRecoveryInspector.php';
    require_once __DIR__ . '/../../../../sandadmin-demo/server/plugin/sandpackage/app/logic/FailedUpgradeRecoveryFileTransaction.php';
    require_once __DIR__ . '/../../../../sandadmin-demo/server/plugin/sandpackage/app/logic/InstallLogic.php';

    $passed = 0;
    $check = static function (bool $ok, string $message) use (&$passed): void { if (!$ok) throw new \RuntimeException($message); $passed++; };
    $reject = static function (callable $call, string $message) use ($check): void { try { $call(); } catch (\Throwable) { $check(true, $message); return; } $check(false, $message); };
    $write = static function (string $path, string $content): void { if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) throw new \RuntimeException('mkdir failed'); if (file_put_contents($path, $content) === false || !chmod($path, 0600)) throw new \RuntimeException('write failed'); };
    $delete = static function (string $path) use (&$delete): void { if (!file_exists($path) && !is_link($path)) return; if (is_file($path) || is_link($path)) { unlink($path); return; } foreach (new \FilesystemIterator($path) as $item) $delete($item->getPathname()); rmdir($path); };
    $snapshot = static function (string $path): string { $items = []; $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)); foreach ($iterator as $item) { if (!$item->isFile() || $item->isLink()) throw new \RuntimeException('unsafe sentinel'); $items[str_replace($path . DIRECTORY_SEPARATOR, '', $item->getPathname())] = hash_file('sha256', $item->getPathname()); } ksort($items); return hash('sha256', json_encode($items, JSON_THROW_ON_ERROR)); };
    $methodSource = static function (string $method): string { $reflection = new \ReflectionMethod(InstallLogic::class, $method); $lines = file($reflection->getFileName(), FILE_IGNORE_NEW_LINES); return implode("\n", array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1)); };

    try {
        $write($root . '/sentinel/registry/other.ini', "app=unrelated-plugin\nstate=1\nstage=completed\n"); $write($root . '/sentinel/files/other.txt', 'unchanged'); $write($root . '/sentinel/service/catalog.json', '{"service":"unrelated"}'); $sentinel = $snapshot($root . '/sentinel');
        $info = $root . '/runtime/sandpackage/fixture-app/info.ini'; $write($info, "app=fixture-app\nversion=1.1.0\nstate=1\nstage=completed\n");
        $logic = new InstallLogic('fixture-app'); $gate = new \ReflectionMethod(InstallLogic::class, 'assertNotFailedUpgradeRecovery'); $gate->setAccessible(true); $gate->invoke($logic);
        $check($logic->getInfo()['state'] === 1 && $logic->getInfo()['stage'] === 'completed', 'A: healthy state=1/completed was not read by real InstallLogic');

        foreach (['upload', 'uploadFromPath', 'install', 'uninstall', 'registerExisting', 'discardCandidate'] as $entry) $check(str_contains($methodSource($entry), '$this->assertNotFailedUpgradeRecovery();'), "B: $entry lacks normal failed-upgrade gate");
        $hash = str_repeat('a', 64); $failed = ['app' => 'fixture-app', 'version' => '1.1.0', 'upgrade_from_version' => '1.0.0', 'state' => 8, 'stage' => 'failed', 'failed_stage' => 'database_update', 'update' => 1, 'package_backup_id' => 'fixture-app-package-20260912000000-abcdef123456', 'registration_manifest' => $hash, 'runtime_manifest' => $hash];
        $write($info, implode("\n", array_map(static fn (string $key, mixed $value): string => "$key=$value", array_keys($failed), $failed)) . "\n");
        foreach (['install', 'upload', 'withdraw'] as $attempt) $reject(static fn () => $gate->invoke($logic), "B: $attempt simulation escaped real failed-upgrade gate");
        $inspector = new FailedUpgradeRecoveryInspector(); $reject(static fn () => $inspector->inspect('fixture-app', $failed + ['candidate_archive_sha256' => $hash], ['required' => false, 'backup_id' => $failed['package_backup_id'], 'diff' => []]), 'B: partial descriptor identity was accepted');
        foreach (['candidate_archive_sha256', 'candidate_payload_manifest_sha256', 'recovery_descriptor_sha256', 'update_sql_sha256'] as $field) $failed[$field] = $hash;
        $inspection = $inspector->inspect('fixture-app', $failed, ['required' => false, 'backup_id' => $failed['package_backup_id'], 'diff' => []]); $check($inspection['allowed_actions'] === ['prepare_failed_upgrade_replacement'], 'B: failed state exposed an ordinary retry action');

        $candidate = $root . '/candidate'; mkdir($candidate, 0700, true); $write($candidate . '/info.ini', "app=fixture-app\nversion=1.1.0\n"); $write($candidate . '/update.sql', 'select 1;');
        $identity = new FailedUpgradePackageIdentity(); $payload = $identity->descriptorPayloadDigest($candidate, FailedUpgradePackageIdentity::NORMALIZED_PACKAGE_MANIFEST_V1, 'fixture-app');
        $descriptor = ['schema' => 'sandpackage.failed-upgrade-recovery/v2', 'app' => 'fixture-app', 'from_version' => '1.0.0', 'to_version' => '1.1.0', 'candidate_payload' => ['algorithm' => FailedUpgradePackageIdentity::NORMALIZED_PACKAGE_MANIFEST_V1, 'digest' => $payload], 'update_lifecycle' => ['path' => 'update.sql', 'sha256' => hash_file('sha256', $candidate . '/update.sql')], 'profile' => ['schema' => 'sandpackage.failed-upgrade-recovery-profile/v2', 'id' => 'fixture_state', 'app' => 'fixture-app', 'from_version' => '1.0.0', 'to_version' => '1.1.0', 'state' => 'partial', 'assertions' => [['type' => 'relation_absent', 'name' => 'fixture_app_future']]]];
        $raw = FailedUpgradeRecoveryVerifier::canonicalJson($descriptor); $write($candidate . '/recovery/failed-upgrade.v2.json', $raw); $check($identity->readDescriptor($candidate) === $raw, 'B: real canonical recovery descriptor was not accepted'); $write($candidate . '/recovery/failed-upgrade.v2.json', $raw . "\n"); $reject(static fn () => $identity->readDescriptor($candidate), 'B: non-canonical recovery descriptor was accepted');

        $retry = $methodSource('retryFailedUpgrade'); $compensation = $methodSource('compensateInstallationFailure'); $check(str_contains($retry, 'executeLifecycleSql') && str_contains($retry, 'deployFilesWithRecovery') && str_contains($retry, 'registerServiceCatalog') && !str_contains($compensation, 'executeLifecycleSql'), 'C: source ordering or no-SQL-compensation premise changed'); $check(!in_array('resumeAfterDb', get_class_methods(InstallLogic::class), true), 'C: resumeAfterDb unexpectedly exists; reassess request');

        $markInstalled = $methodSource('markInstalled'); $backupPackage = $methodSource('backupPackage'); $rename = strpos($backupPackage, "renameCandidatePath(\$this->appDir, \$target, 'backup.rename')"); $backedUp = strpos($backupPackage, "\$payload['phase'] = 'backed_up';"); $comparison = strpos($backupPackage, "hash_equals((string) \$backupInfo['registration_manifest'], \$deploymentManifest)"); $preRestore = strpos($backupPackage, 'assertPreUpgradePackageIdentity($target, $payload);'); $rollback = strpos($backupPackage, "backup.rollback.rename"); $postRestore = strpos($backupPackage, 'assertPreUpgradePackageIdentity($this->appDir, $payload);'); $check(!str_contains($markInstalled, "['registration_manifest']") && $rename !== false && $backedUp !== false && $comparison !== false && $preRestore !== false && $rollback !== false && $postRestore !== false && $rename < $backedUp && $backedUp < $comparison && $comparison < $preRestore && $preRestore < $rollback && $rollback < $postRestore, 'E: current manifest drift must remain frozen as rename then backed_up then comparison, with pre-restore assertion before rollback rename and post-restore assertion');

        $source = $root . '/backup/runtime'; $target = $root . '/target/runtime'; $write($source . '/app.txt', 'stable'); $write($target . '/app.txt', 'drift'); $transaction = new FailedUpgradeRecoveryFileTransaction($root . '/managed'); $manifest = $transaction->manifest($source); $runtimeHash = hash('sha256', json_encode([$manifest], JSON_THROW_ON_ERROR));
        $reject(static fn () => $transaction->restore('fixture-app', $failed['package_backup_id'], $runtimeHash, [$source], [$target], [$manifest], static function (string $point): void { if ($point === 'runtime_restore.journal.prepared') throw new \RuntimeException('fault'); }), 'D: injected temporary recovery fault did not stop'); $check($transaction->hasPending('fixture-app') && $snapshot($root . '/sentinel') === $sentinel, 'D: temporary failure changed the local canary'); $transaction->restore('fixture-app', $failed['package_backup_id'], $runtimeHash, [$source], [$target], [$manifest]); $check($snapshot($root . '/sentinel') === $sentinel, 'D: temporary resume changed the local canary'); $transaction->restore('fixture-app', $failed['package_backup_id'], $runtimeHash, [$source], [$target], [$manifest]); $check(!$transaction->hasPending('fixture-app') && $transaction->manifest($target) === $manifest && $snapshot($root . '/sentinel') === $sentinel, 'D: repeated temporary recovery changed the local canary');
        foreach (['restoreRuntimeFromBackup', 'replaceFailedUpgradeCandidate', 'retryFailedUpgrade'] as $entry) $check(str_contains($methodSource($entry), '$this->acquireOperationLock();'), "D: $entry lacks app operation lock");
        echo "PASS HOST-202609-001 neutral reproducer ($passed checks; no DB/service/host writes)\n";
    } finally { $delete($root); }
}
