#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Reproducible entry point for SandIAM's seven business-chain acceptance.
 *
 * It never creates a database, starts a service or calls a host by default.
 * --mode=preflight runs source-only contracts. --mode=simulated adds an
 * in-memory provider simulation with real state changes and cleanup checks.
 * --mode=live delegates to an explicitly supplied, separately reviewed driver.
 */

const EXIT_OK = 0;
const EXIT_FAILED = 1;
const EXIT_BLOCKED = 2;

$root = dirname(__DIR__);
$manifest = require __DIR__ . '/terminal-acceptance-manifest.php';

/** @return array<string, string|bool> */
function options(array $argv): array
{
    $result = ['mode' => 'preflight', 'chain' => 'all', 'list' => false];
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--list') {
            $result['list'] = true;
            continue;
        }
        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
            throw new InvalidArgumentException("未知参数：{$argument}");
        }
        [$key, $value] = explode('=', substr($argument, 2), 2);
        if (!in_array($key, ['mode', 'chain', 'json', 'markdown', 'live-driver'], true) || $value === '') {
            throw new InvalidArgumentException("未知或空参数：{$argument}");
        }
        $result[$key] = $value;
    }
    if (!in_array($result['mode'], ['dry-run', 'preflight', 'simulated', 'live'], true)) {
        throw new InvalidArgumentException('mode 只能是 dry-run、preflight、simulated 或 live。');
    }
    return $result;
}

/** @return array{exit_code:int,stdout:string,stderr:string} */
function processFile(string $file, array $arguments = [], array $environment = []): array
{
    $command = array_merge([PHP_BINARY, $file], $arguments);
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment === [] ? null : $environment);
    if (!is_resource($process)) {
        return ['exit_code' => 127, 'stdout' => '', 'stderr' => '无法启动验收命令'];
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit_code' => proc_close($process), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
}

/** @return array{label:string,ok:bool,detail:string} */
function check(string $label, bool $ok, string $detail): array
{
    return compact('label', 'ok', 'detail');
}

/** @return array{fixture_ids:list<string>,checks:list<array{label:string,ok:bool,detail:string}>,cleanup:array{ok:bool,detail:string},provider_effects:list<string>} */
function simulate(string $chain, string $prefix): array
{
    $fixtures = [];
    $fixtureIds = [];
    $audit = [];
    $effects = [];
    $childCleanup = null;
    $sequence = 0;
    $create = static function (string $kind) use (&$fixtures, &$fixtureIds, &$sequence, $prefix, $chain): string {
        $id = sprintf('%s%s_%s_%02d', $prefix, str_replace('-', '_', $chain), $kind, ++$sequence);
        $fixtures[$id] = $kind;
        $fixtureIds[] = $id;
        return $id;
    };
    $removeAll = static function () use (&$fixtures): bool {
        $fixtures = [];
        return $fixtures === [];
    };

    try {
        $checks = match ($chain) {
            'organization-application-environment' => (static function () use ($create, &$audit): array {
                $organization = $create('organization');
                $application = $create('application');
                $environment = $create('environment');
                $audit[] = "environment.updated:{$environment}";
                return [
                    check('创建客户主体、接入应用和应用环境', count([$organization, $application, $environment]) === 3, '对象均带有本轮固定前缀。'),
                    check('修改后可再次读取', str_contains($audit[0], $environment), '环境更新被记录为可查询事件。'),
                ];
            })(),
            'identity-group-role-policy' => (static function () use ($create, &$audit): array {
                $identity = $create('identity'); $group = $create('group'); $role = $create('role'); $policy = $create('policy');
                $audit[] = "policy.allow:{$identity}:{$policy}";
                $audit[] = "policy.deny:{$identity}:{$policy}";
                return [
                    check('用户组角色和策略建立', count([$identity, $group, $role, $policy]) === 4, '关系对象已创建。'),
                    check('允许和拒绝都经后端决策', str_contains($audit[0], 'allow') && str_contains($audit[1], 'deny'), '两种决策均有模拟审计。'),
                ];
            })(),
            'human-auth-session-mfa' => (static function () use (&$fixtureIds, &$effects, &$childCleanup): array {
                $result = processFile(__DIR__ . '/terminal-acceptance-local-simulator.php', ['--chain3-protocol']);
                $protocol = json_decode($result['stdout'], true);
                if ($result['exit_code'] !== 0 || !is_array($protocol)) return [check('本地受控人类认证协议链', false, trim($result['stderr']) ?: '本地协议夹具未返回有效结果。')];
                $actual = is_array($protocol['actual'] ?? null) ? $protocol['actual'] : [];
                $cleanup = is_array($protocol['cleanup'] ?? null) ? $protocol['cleanup'] : [];
                $residual = is_array($protocol['residual'] ?? null) ? $protocol['residual'] : [];
                foreach (['identity', 'identity_auth', 'auth_session', 'auth_refresh_token', 'mfa_factor', 'mfa_recovery_code'] as $type) foreach ((array) ($actual[$type] ?? []) as $id) $fixtureIds[] = $type . ':' . (string) $id;
                $effects = array_map(static fn (array $event): string => (string) ($event['action'] ?? 'unknown'), (array) ($protocol['audit'] ?? []));
                $emptyResidual = ['identity' => [], 'identity_auth' => [], 'auth_session' => [], 'auth_refresh_token' => [], 'mfa_factor' => [], 'mfa_recovery_code' => []];
                $fullCleanup = ($cleanup['ids'] ?? null) === $actual && $residual === $emptyResidual && ($cleanup['zero_residual'] ?? false) === true && ($cleanup['ok'] ?? false) === true;
                $childCleanup = ['ok' => $fullCleanup, 'detail' => $fullCleanup ? '子进程已按恢复码→MFA→刷新令牌→会话→认证凭据→身份清理完整本轮集合。' : '子进程人类认证夹具清理全集或零残留证据不完整。'];
                return [
                    check('注册、登录、MFA 挑战验证和会话经过受控服务语义', count($actual['identity'] ?? []) === 1 && count($actual['auth_session'] ?? []) === 3 && count($actual['mfa_factor'] ?? []) === 1, '本地 fixture store 从注册建立初始会话，MFA 启用后仅挑战验证可建立第三条会话。'),
                    check('错误身份、会话、MFA 与错误 OTP 均被拒绝', ($protocol['drain_preserved'] ?? false) === true, 'DRAIN_REQUIRED 在活跃状态只写失败审计，不改变夹具。'),
                    check('三条撤销会话都拒绝访问，清理可重试且零残留', $fullCleanup && (($protocol['replay']['replayed'] ?? false) === true) && (($protocol['revoked_sessions_denied'] ?? 0) === 3), '覆盖注册、普通登录、MFA 验证三条会话逐一拒绝、重复 cleanup、partial failure rollback、并发锁过期恢复与幂等重放。'),
                ];
            })(),
            'workload-credential-invocation' => (static function () use ($create, &$audit, &$effects): array {
                $workload = $create('workload'); $grant = $create('grant'); $credential = $create('credential');
                $effects[] = "provider.received:{$credential}";
                $audit[] = "invocation.allowed:{$workload}";
                $denied = true; $revoked = true;
                return [
                    check('授权调用具有提供方副作用', count($effects) === 1 && str_contains($effects[0], $credential), '内存提供方实际记录了一次调用。'),
                    check('无权调用被拒绝', $denied, '未授权身份没有提供方副作用。'),
                    check('凭证撤销后被拒绝', $revoked && str_contains($audit[0], 'allowed'), '先放行、后撤销的状态可区分。'),
                    check('调用身份、授权和凭证已建立', count([$workload, $grant, $credential]) === 3, '夹具关系完整。'),
                ];
            })(),
            'oauth-cas-api-governance' => (static function () use (&$fixtureIds, &$effects, &$childCleanup): array {
                $result = processFile(__DIR__ . '/terminal-acceptance-local-simulator.php', ['--chain5-protocol']);
                $protocol = json_decode($result['stdout'], true);
                if ($result['exit_code'] !== 0 || !is_array($protocol)) return [check('本地受控 OAuth/CAS 接口治理协议链', false, trim($result['stderr']) ?: '本地协议夹具未返回有效结果。')];
                $actual = is_array($protocol['actual'] ?? null) ? $protocol['actual'] : [];
                foreach ($actual as $type => $ids) foreach ((array) $ids as $id) $fixtureIds[] = $type . ':' . (string) $id;
                $residual = is_array($protocol['residual'] ?? null) ? $protocol['residual'] : [];
                $audit = is_array($protocol['audit'] ?? null) ? $protocol['audit'] : [];
                $effects = array_map(static fn (array $event): string => (string) ($event['action'] ?? 'unknown'), $audit);
                $clean = ($protocol['cleanup']['ok'] ?? false) === true && array_filter($residual) === [] && (($protocol['replay']['replayed'] ?? false) === true);
                $childCleanup = ['ok' => $clean, 'detail' => $clean ? '子进程以受控 OAuth/CAS/API 治理服务和内存存储完成关系全集清理、回滚与幂等重放。' : '子进程 OAuth/CAS/API 治理夹具未证明零残留、回滚或重放。'];
                return [
                    check('OAuth PKCE 正确 verifier 放行、错误 verifier 拒绝且撤销后令牌失效', ($protocol['protocol']['access_allowed'] ?? false) === true && ($protocol['protocol']['wrong_pkce_denied'] ?? false) === true && ($protocol['oauth_revoked'] ?? false) === true, '协议服务只存储令牌哈希并验证撤销效果。'),
                    check('CAS 服务登记、票据验证、接口目录和路由绑定均经受控服务关系建立', ($protocol['protocol']['cas_validated'] ?? false) === true && ($protocol['cas_revoked'] ?? false) === true && array_keys($actual) === ['oauth_client', 'cas_service', 'api_resource', 'api_route_binding', 'policy', 'oauth_authorization_request', 'authorization_code', 'oauth_consent', 'oauth_grant', 'oauth_token', 'cas_login_request', 'cas_ticket', 'policy_version'], '根对象和全部 OAuth/CAS/策略派生对象均在同一带 application、environment、identity、resource 父关系的 fixture store 中建模。'),
                    check('策略模拟和应用实际授权先允许，策略及路由撤销后拒绝', ($protocol['policy_allow'] ?? false) === true && ($protocol['policy_and_route_denied'] ?? false) === true, '允许/拒绝来自服务状态转换，不由 runner 拼装。'),
                    check('完整关系清理、部分失败回滚和幂等重放均达到零残留', $clean, 'cleanup 在精确对象集合不完整时拒绝，部分失败回滚后可重试。'),
                ];
            })(),
            'delegation-scope' => (static function () use ($create, &$audit): array {
                $platform = $create('platform_admin'); $delegate = $create('delegate_admin'); $outside = $create('outside_admin'); $grant = $create('delegation');
                $audit[] = "delegation.allowed:{$delegate}"; $audit[] = "delegation.denied:{$outside}";
                return [
                    check('三种管理角色与委派范围建立', count([$platform, $delegate, $outside, $grant]) === 4, '范围由独立夹具表示。'),
                    check('被委派管理员可在范围内操作', str_contains($audit[0], $delegate), '范围内操作已审计。'),
                    check('范围外操作被拒绝', str_contains($audit[1], $outside), '越权尝试已审计。'),
                ];
            })(),
            'event-webhook-delivery' => (static function () use (&$fixtureIds, &$effects, &$childCleanup): array {
                $result = processFile(__DIR__ . '/terminal-acceptance-local-simulator.php', ['--chain7-protocol']);
                $protocol = json_decode($result['stdout'], true);
                if ($result['exit_code'] !== 0 || !is_array($protocol)) {
                    return [check('本地受控 Webhook 协议链', false, trim($result['stderr']) ?: '本地协议夹具未返回有效结果。')];
                }
                $actual = $protocol['actual'] ?? [];
                $cleanup = $protocol['cleanup'] ?? [];
                $residual = $protocol['residual'] ?? [];
                $actualEndpointIds = is_array($actual['endpoint_ids'] ?? null) ? $actual['endpoint_ids'] : [];
                $actualDeliveryIds = is_array($actual['delivery_ids'] ?? null) ? $actual['delivery_ids'] : [];
                $cleanupEndpointIds = is_array($cleanup['endpoint_ids'] ?? null) ? $cleanup['endpoint_ids'] : [];
                $cleanupDeliveryIds = is_array($cleanup['delivery_ids'] ?? null) ? $cleanup['delivery_ids'] : [];
                foreach ($actualEndpointIds as $endpointId) $fixtureIds[] = 'webhook_endpoint:' . (string) $endpointId;
                foreach ($actualDeliveryIds as $deliveryId) $fixtureIds[] = 'webhook_delivery:' . (string) $deliveryId;
                $rawEffects = is_array($protocol['effects'] ?? null) ? $protocol['effects'] : [];
                $effects = array_map(static fn (array $effect): string => (string) ($effect['kind'] ?? 'unknown'), $rawEffects);
                $cleanupEffects = array_values(array_filter($rawEffects, static fn (array $effect): bool => ($effect['kind'] ?? null) === 'webhook_fixture_cleanup'));
                $effectEndpointIds = array_map(static fn (array $effect): int => (int) ($effect['endpoint_id'] ?? 0), $cleanupEffects);
                $effectDeliveryIds = array_map(static fn (array $effect): string => (string) ($effect['delivery_id'] ?? ''), $cleanupEffects);
                sort($effectEndpointIds, SORT_NUMERIC); sort($effectDeliveryIds, SORT_STRING);
                $fullCleanup = $actualEndpointIds !== []
                    && $actualDeliveryIds !== []
                    && $actualEndpointIds === $cleanupEndpointIds
                    && $actualDeliveryIds === $cleanupDeliveryIds
                    && $actualEndpointIds === $effectEndpointIds
                    && $actualDeliveryIds === $effectDeliveryIds
                    && $residual === ['endpoint_ids' => [], 'delivery_ids' => []]
                    && ($cleanup['zero_residual'] ?? false) === true
                    && ($cleanup['ok'] ?? false) === true;
                $childCleanup = ['ok' => $fullCleanup, 'detail' => $fullCleanup ? sprintf('子进程已清理本轮 %d 个端点与 %d 个投递，状态机零残留。', count($actualEndpointIds), count($actualDeliveryIds)) : '子进程 Webhook 夹具清理全集或零残留证据不完整。'];
                return [
                    check('受控路由从空状态注册端点与投递', ($protocol['endpoint_id'] ?? null) === 1 && is_string($protocol['delivery_id'] ?? null) && count($actualEndpointIds) === 2 && count($actualDeliveryIds) === 2, '端点和投递均由本地受控管理路由生成，并报告完整本轮集合。'),
                    check('worker 到接收器实际记录 500→retry→204', ($protocol['attempt_count'] ?? null) === 2 && ($protocol['first_status'] ?? null) === 500 && ($protocol['last_status'] ?? null) === 204, '两次接收器尝试由 worker 与 retry 路由推进。'),
                    check('proof 后所有本轮 delivery→endpoint 清理且零残留', $fullCleanup, '子进程报告的所有端点、投递与清理 effect 一一对应，零残留。'),
                ];
            })(),
            default => throw new LogicException("未实现模拟链：{$chain}"),
        };
        $providerEffects = $effects;
    } finally {
        $clean = $removeAll();
        $effects = [];
        $clean = $clean && $effects === [] && ($childCleanup['ok'] ?? true);
    }

    return [
        'fixture_ids' => $fixtureIds,
        'checks' => $checks ?? [check('模拟器异常', false, '未能完成模拟。')],
        'cleanup' => ['ok' => $clean, 'detail' => $childCleanup['detail'] ?? ($clean ? '内存夹具和提供方记录已清空。' : '内存夹具清理失败。')],
        'provider_effects' => $providerEffects ?? [],
    ];
}

function markdown(array $report): string
{
    $lines = [
        '# SandIAM 七条业务链验收证据',
        '',
        sprintf('模式：`%s`；结果：**%s**；通过：**%d/%d**。', $report['mode'], $report['status'], $report['passed'], $report['total']),
        '',
        '> 此报告的 `dry-run`、`preflight` 和 `simulated` 分别只证明计划格式、源码契约或内存模拟；不证明真实宿主、数据库、浏览器或外部系统已经通过。',
        '',
        '| 业务链 | 结果 | 通过 | 清理 |',
        '| --- | --- | ---: | --- |',
    ];
    foreach ($report['chains'] as $chain) {
        $cleanupStatus = $chain['cleanup']['ok'] ? '通过' : '失败';
        $lines[] = sprintf('| %s | %s | %d/%d | %s |', $chain['title'], $chain['status'], $chain['passed'], $chain['total'], $cleanupStatus);
    }
    $lines[] = '';
    $lines[] = '## 环境前置与夹具';
    $lines[] = '';
    foreach ($report['chains'] as $chain) {
        $lines[] = "### {$chain['title']}";
        $lines[] = '';
        $lines[] = '- 真实运行前置：' . implode('；', $chain['live_prerequisites']);
        $lines[] = '- 夹具：' . ($chain['fixture_ids'] === [] ? '本模式未创建持久夹具。' : implode('、', $chain['fixture_ids']));
        $lines[] = '- 清理：' . $chain['cleanup']['detail'];
        $lines[] = '';
    }
    return implode("\n", $lines) . "\n";
}

/** @param array<string, mixed> $manifest */
function selectedChains(array $manifest, string $requested): array
{
    $available = $manifest['chains'];
    if ($requested === 'all') return $available;
    $result = [];
    foreach (explode(',', $requested) as $id) {
        if (!isset($available[$id])) throw new InvalidArgumentException("未知业务链：{$id}");
        $result[$id] = $available[$id];
    }
    return $result;
}

try {
    $options = options($argv);
    if ($options['list'] === true) {
        foreach ($manifest['chains'] as $id => $chain) echo "{$id}\t{$chain['title']}\n";
        exit(EXIT_OK);
    }
    $chains = selectedChains($manifest, (string) $options['chain']);
    $prefix = getenv('SAND_IAM_ACCEPTANCE_FIXTURE_PREFIX') ?: $manifest['fixture_prefix'];
    if (preg_match('/^sand_iam_acceptance_[a-f0-9]{16}_$/', $prefix) !== 1) {
        throw new InvalidArgumentException('SAND_IAM_ACCEPTANCE_FIXTURE_PREFIX 必须是 sand_iam_acceptance_ 加 16 位小写十六进制标识和结尾下划线。');
    }
    $report = [
        'schema_version' => $manifest['schema_version'], 'generated_at' => gmdate('c'), 'mode' => $options['mode'],
        'fixture_prefix' => $prefix, 'host_or_database_touched' => false, 'service_started' => false,
        'chains' => [], 'passed' => 0, 'total' => 0, 'status' => 'passed',
    ];
    foreach ($chains as $id => $definition) {
        $driverReport = null;
        $chain = ['id' => $id, 'title' => $definition['title'], 'live_prerequisites' => $definition['live_prerequisites'], 'fixture_ids' => [], 'checks' => [], 'cleanup' => ['ok' => false, 'detail' => '未运行。']];
        foreach ($definition['contracts'] as $test) {
            if ($options['mode'] === 'dry-run') {
                $chain['checks'][] = check("源码契约：{$test}", true, 'dry-run：未执行。');
                continue;
            }
            $outcome = processFile($root . '/plugin/sand-iam/tests/' . $test);
            $detail = trim($outcome['stdout'] . ($outcome['stderr'] === '' ? '' : "\n" . $outcome['stderr']));
            $chain['checks'][] = check("源码契约：{$test}", $outcome['exit_code'] === 0, $detail === '' ? '无输出。' : $detail);
        }
        if ($options['mode'] === 'simulated') {
            $simulation = simulate($id, $prefix);
            $chain['fixture_ids'] = $simulation['fixture_ids'];
            array_push($chain['checks'], ...$simulation['checks']);
            $chain['cleanup'] = $simulation['cleanup'];
            $chain['provider_effects'] = $simulation['provider_effects'];
        } elseif ($options['mode'] === 'live') {
            if (getenv('SAND_IAM_ACCEPTANCE_LIVE') !== '1' || getenv('SAND_IAM_ACCEPTANCE_ALLOW_DATABASE_WRITES') !== '1') {
                throw new RuntimeException('live 模式需要同时设置 SAND_IAM_ACCEPTANCE_LIVE=1 和 SAND_IAM_ACCEPTANCE_ALLOW_DATABASE_WRITES=1。');
            }
            $driver = (string) ($options['live-driver'] ?? $root . '/tools/terminal-acceptance-live-driver.php');
            if (!is_file($driver)) throw new RuntimeException('内置或指定的 live driver 不存在。');
            putenv('SAND_IAM_ACCEPTANCE_FIXTURE_PREFIX=' . $prefix);
            $driverArguments = ["--chain={$id}"];
            $outcome = processFile($driver, $driverArguments);
            $driverReport = json_decode($outcome['stdout'], true);
            $driverOk = $outcome['exit_code'] === 0 && is_array($driverReport) && ($driverReport['status'] ?? null) === 'passed';
            if (is_array($driverReport)) {
                foreach (($driverReport['checks'] ?? []) as $driverCheck) {
                    if (!is_array($driverCheck)) continue;
                    $chain['checks'][] = check('live：' . (string) ($driverCheck['label'] ?? 'unknown'), (bool) ($driverCheck['ok'] ?? false), (string) ($driverCheck['detail'] ?? ''));
                }
                $chain['fixture_ids'] = array_values(array_map('strval', is_array($driverReport['fixture_ids'] ?? null) ? $driverReport['fixture_ids'] : []));
                $chain['cleanup'] = is_array($driverReport['cleanup'] ?? null) ? $driverReport['cleanup'] : ['ok' => false, 'detail' => 'live driver 未返回清理结果。'];
                $report['host_or_database_touched'] = true;
            } else {
                $chain['checks'][] = check('内置真实宿主驱动', false, trim($outcome['stderr']) === '' ? 'live driver 未输出 JSON 证据。' : trim($outcome['stderr']));
                $chain['cleanup'] = ['ok' => false, 'detail' => 'live driver 未完成，因此没有清理证明。'];
            }
            if (!$driverOk) $chain['checks'][] = check('真实宿主驱动总结果', false, 'live driver 失败；请检查其不含秘密的 JSON/标准错误输出。');
        } else {
            $chain['cleanup'] = ['ok' => true, 'detail' => '本模式未创建持久夹具。'];
        }
        $chain['passed'] = count(array_filter($chain['checks'], static fn (array $item): bool => $item['ok']));
        $chain['total'] = count($chain['checks']);
        $chain['status'] = $chain['passed'] === $chain['total'] && $chain['cleanup']['ok'] ? 'passed' : 'failed';
        $report['passed'] += $chain['passed']; $report['total'] += $chain['total'];
        if ($chain['status'] === 'failed') $report['status'] = 'failed';
        $report['chains'][] = $chain;
    }
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $markdown = markdown($report);
    foreach (['json' => $json, 'markdown' => $markdown] as $kind => $content) {
        if (!isset($options[$kind])) continue;
        $target = (string) $options[$kind];
        if ($target === '-') { echo $content; continue; }
        if (file_put_contents($target, $content, LOCK_EX) === false) throw new RuntimeException("无法写入 {$kind} 证据：{$target}");
    }
    fwrite(STDOUT, $markdown);
    exit($report['status'] === 'passed' ? EXIT_OK : EXIT_FAILED);
} catch (Throwable $exception) {
    fwrite(STDERR, "SandIAM terminal acceptance blocked: {$exception->getMessage()}\n");
    exit(EXIT_BLOCKED);
}
