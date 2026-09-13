<?php

declare(strict_types=1);

require_once __DIR__ . '/Contract.php';

/**
 * v1 deliberately has no transport or business adapter. Live is unsupported.
 */
final class ConsumerAcceptanceRuntime
{
    public function validate(array $plan, string $planSha256): array
    {
        $plan = ConsumerAcceptanceContract::plan($plan);
        return $this->report($plan, $planSha256, 'validate', 'validated', 'not_run', 'not_attempted');
    }

    public function live(array $plan, string $planSha256, string $secretsFile, string $sourceRoot): array
    {
        $plan = ConsumerAcceptanceContract::plan($plan);
        self::liveSecrets($secretsFile, $sourceRoot);
        return $this->report($plan, $planSha256, 'live', 'unsupported', 'blocked', 'not_started');
    }

    public static function liveSecrets(string $file, string $sourceRoot): void
    {
        $root = self::directory($sourceRoot, 'source root');
        $file = self::regularFile($file, 'live secrets file');
        if ($file === $root || str_starts_with($file, $root . '/')) throw new RuntimeException('live secrets file must be outside source tree');
        $stat = lstat($file);
        if ($stat === false || ($stat['mode'] & 0777) !== 0600) throw new RuntimeException('live secrets file must have mode 0600');
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        if (!is_int($uid) || (int) $stat['uid'] !== $uid) throw new RuntimeException('live secrets file must be owned by current user');
    }

    public static function writeReport(string $output, array $report): void
    {
        ConsumerAcceptanceContract::report($report);
        if ($output === '' || str_contains($output, "\0")) throw new InvalidArgumentException('report output path is invalid');
        $parent = self::directory(dirname($output), 'report parent');
        $name = basename($output);
        if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '\\')) throw new InvalidArgumentException('report filename is invalid');
        $destination = $parent . '/' . $name;
        clearstatcache(true, $destination);
        if (file_exists($destination) || is_link($destination)) throw new RuntimeException('report output already exists');
        $temporary = tempnam($parent, '.consumer-acceptance-');
        if (!is_string($temporary)) throw new RuntimeException('cannot create report temporary file');
        try {
            $stat = lstat($temporary);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || chmod($temporary, 0600) !== true) throw new RuntimeException('report temporary file is unsafe');
            $bytes = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
            $handle = fopen($temporary, 'wb');
            if ($handle === false || fwrite($handle, $bytes) !== strlen($bytes) || fflush($handle) !== true) {
                if (is_resource($handle)) fclose($handle);
                throw new RuntimeException('cannot write report temporary file');
            }
            if (function_exists('fsync') && fsync($handle) !== true) {
                fclose($handle);
                throw new RuntimeException('cannot fsync report temporary file');
            }
            fclose($handle);
            if (link($temporary, $destination) !== true) throw new RuntimeException('cannot atomically publish report');
        } finally {
            clearstatcache(true, $temporary);
            if (lstat($temporary) !== false) unlink($temporary);
        }
    }

    /** @return array{bytes:string,sha256:string} */
    public static function readPlanFile(string $plan): array
    {
        if ($plan === '' || str_contains($plan, "\0") || !str_starts_with($plan, '/') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $plan) === 1) {
            throw new InvalidArgumentException('plan must be an absolute local file path');
        }
        $file = self::regularFile($plan, 'plan');
        $bytes = file_get_contents($file);
        if (!is_string($bytes)) throw new RuntimeException('cannot read plan');
        return ['bytes' => $bytes, 'sha256' => hash('sha256', $bytes)];
    }

    private function report(array $plan, string $planSha256, string $mode, string $status, string $stepStatus, string $cleanupState): array
    {
        $fixtures = $plan['fixtures'];
        $fixtureIds = ['organization_id', 'application_id', 'identity_id', 'business_object_id'];
        $fixtureIds = array_map(static fn (string $key): string => $fixtures[$key], $fixtureIds);
        return [
            'schema' => ConsumerAcceptanceContract::REPORT_SCHEMA,
            'mode' => $mode, 'status' => $status, 'real_l04' => false,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'), 'run_id' => $plan['run_id'], 'plan_sha256' => $planSha256,
            'candidate' => $plan['candidate'], 'gate_receipts' => $plan['gate_receipts'],
            'fixtures' => $fixtures, 'fixture_scope_sha256' => $plan['fixture_scope_sha256'], 'cleanup_manifest_sha256' => $plan['cleanup_manifest_sha256'],
            'steps' => array_map(static fn (string $id): array => ['id' => $id, 'status' => $stepStatus], ConsumerAcceptanceContract::STEPS),
            'business_attempted' => false, 'failure_stops_business' => true,
            'cleanup_boundary' => ['state' => $cleanupState, 'fixture_ids' => $fixtureIds],
        ];
    }

    private static function directory(string $path, string $label): string
    {
        self::noSymlink($path, $label);
        $real = realpath($path);
        if ($real === false || !is_dir($real)) throw new RuntimeException($label . ' must be an existing directory');
        return rtrim($real, '/');
    }

    private static function regularFile(string $path, string $label): string
    {
        self::noSymlink($path, $label);
        $real = realpath($path);
        $stat = $real === false ? false : lstat($real);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1) throw new RuntimeException($label . ' must be a non-hardlinked regular file');
        return $real;
    }

    private static function noSymlink(string $path, string $label): void
    {
        if ($path === '' || str_contains($path, "\0")) throw new InvalidArgumentException($label . ' path is invalid');
        $absolute = str_starts_with($path, '/') ? $path : getcwd() . '/' . $path;
        $current = '/';
        foreach (explode('/', $absolute) as $part) {
            if ($part === '') continue;
            if ($part === '.' || $part === '..') throw new RuntimeException($label . ' path traversal is forbidden');
            $current .= ($current === '/' ? '' : '/') . $part;
            $stat = lstat($current);
            if ($stat === false || ($stat['mode'] & 0170000) === 0120000) throw new RuntimeException($label . ' has a missing or symlink component');
        }
    }
}
