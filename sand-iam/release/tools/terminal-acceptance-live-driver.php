#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * SandIAM live acceptance plan executor.
 *
 * The plan is data only: no shell, SQL, arbitrary URL or implicit assertion is
 * accepted. A successful HTTP request is never enough to pass a business step.
 */

const LIVE_EXIT_FAILED = 1;
const LIVE_EXIT_BLOCKED = 2;
const SAND_IAM_ACCEPTANCE_FIXTURE_PREFIX_PATTERN = '/^sand_iam_acceptance_[a-f0-9]{16}_$/';
const SAND_IAM_ACCEPTANCE_FIXTURE_REQUEST_ID_PATTERN = '/^sand_iam_acceptance_[a-f0-9]{16}_[A-Za-z0-9][A-Za-z0-9_.:-]{0,58}$/';

/** @return array<string,list<array{table:string,column:string,capture:string}>> */
function livePostgresReadonlyChecks(): array
{
    return [
        'human_auth_artifacts' => [
            ['table' => 'sand_iam_auth_challenge', 'column' => 'identity_id', 'capture' => 'identity_id'],
            ['table' => 'sand_iam_auth_refresh_token', 'column' => 'session_id', 'capture' => 'session_id'],
            ['table' => 'sand_iam_auth_session', 'column' => 'id', 'capture' => 'session_id'],
            ['table' => 'sand_iam_mfa_recovery_code', 'column' => 'factor_id', 'capture' => 'mfa_factor_id'],
            ['table' => 'sand_iam_mfa_factor', 'column' => 'id', 'capture' => 'mfa_factor_id'],
        ],
    ];
}

/** @return array<string,string> */
function liveOptions(array $argv): array
{
    $result = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) throw new InvalidArgumentException("未知参数：{$argument}");
        [$key, $value] = explode('=', substr($argument, 2), 2);
        if ($key !== 'chain' || $value === '') throw new InvalidArgumentException("未知或空参数：{$argument}");
        $result[$key] = $value;
    }
    if (!isset($result['chain'])) throw new InvalidArgumentException('必须指定 --chain=<业务链 ID>。');
    return $result;
}

function liveRequire(string $key): string
{
    $value = trim((string) getenv($key));
    if ($value === '') throw new RuntimeException("缺少 {$key}。");
    return $value;
}

function livePrefix(): string
{
    $prefix = liveRequire('SAND_IAM_ACCEPTANCE_FIXTURE_PREFIX');
    liveValidateFixturePrefix($prefix);
    return $prefix;
}

function liveValidateFixturePrefix(string $prefix): void
{
    if (preg_match(SAND_IAM_ACCEPTANCE_FIXTURE_PREFIX_PATTERN, $prefix) !== 1) throw new RuntimeException('测试前缀不安全。');
}

/** @return list<string> */
function liveReferencedVariables(mixed $value): array
{
    $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    preg_match_all('/\$\{([A-Za-z][A-Za-z0-9_.-]*)\}/', $encoded, $matches);
    return array_values(array_unique($matches[1] ?? []));
}

function liveMethodWriteAllowed(array $target, array $step): bool
{
    $method = $step['method'] ?? null;
    $write = $step['write'] ?? null;
    if (!in_array($method, ['GET', 'POST', 'PATCH', 'DELETE'], true) || !is_bool($write)) return false;
    if ($method === 'GET') return $write === false;
    if (in_array($method, ['PATCH', 'DELETE'], true)) {
        return $write === true
            && ($target['kind'] ?? null) === 'sandiam'
            && str_starts_with((string) ($step['path'] ?? ''), '/api/sand-iam/v1/scim/');
    }
    return $write === true || ($write === false && (
        (($target['kind'] ?? null) === 'external' && in_array($step['effect'] ?? null, ['non_persistent', 'sandiam_invocation_operation'], true))
        || (($target['kind'] ?? null) === 'sandiam' && in_array($step['effect'] ?? null, ['audit_only', 'read_only'], true))
    ));
}

/** @return array{max_attempts:int,interval_ms:int} */
function livePollDefinition(array $step): array
{
    $poll = $step['poll'] ?? null;
    if ($poll === null) return ['max_attempts' => 1, 'interval_ms' => 0];
    if (!is_array($poll)
        || !array_key_exists('max_attempts', $poll)
        || !array_key_exists('interval_ms', $poll)
        || count($poll) !== 2
        || !is_int($poll['max_attempts'])
        || !is_int($poll['interval_ms'])
        || $poll['max_attempts'] < 2
        || $poll['max_attempts'] > 12
        || $poll['interval_ms'] < 100
        || $poll['interval_ms'] > 5000) {
        throw new RuntimeException('poll 必须是 2–12 次、100–5000ms 的有界诊断轮询。');
    }
    if (($step['method'] ?? null) !== 'GET' || ($step['write'] ?? null) !== false) {
        throw new RuntimeException('poll 只允许用于只读 GET 诊断步骤。');
    }
    return ['max_attempts' => $poll['max_attempts'], 'interval_ms' => $poll['interval_ms']];
}

/**
 * @param callable():array<string,mixed> $request
 * @param callable(array<string,mixed>):array{ok:bool,detail:string} $verify
 * @return array{response:array<string,mixed>|null,outcome:array{ok:bool,detail:string},attempts:int}
 */
function liveRunPoll(callable $request, callable $verify, array $poll): array
{
    $response = null;
    $outcome = ['ok' => false, 'detail' => '未执行。'];
    $attempts = 0;
    for ($attempt = 1; $attempt <= $poll['max_attempts']; $attempt++) {
        $attempts++;
        $response = $request();
        $outcome = $verify($response);
        if ($outcome['ok']) break;
        if ($attempt < $poll['max_attempts']) usleep($poll['interval_ms'] * 1000);
    }
    return ['response' => $response, 'outcome' => $outcome, 'attempts' => $attempts];
}

/** @param array<string,mixed> $step @param array<string,mixed> $variables */
function liveShouldRunStep(array $step, array $variables): bool
{
    foreach (($step['run_if_capture_ids'] ?? []) as $name) if (!is_string($name) || !isset($variables[$name])) return false;
    foreach (($step['run_unless_capture_ids'] ?? []) as $name) if (!is_string($name) || isset($variables[$name])) return false;
    return true;
}

/** @return array<string,string> */
function liveFullSuccessVariables(array $chain): array
{
    $variables = [];
    foreach (($chain['steps'] ?? []) as $step) {
        if (!is_array($step)) continue;
        foreach (($step['capture'] ?? []) as $name => $_source) if (is_string($name)) $variables[$name] = 'captured';
    }
    return $variables;
}

/** @return list<array<string,mixed>> */
function liveSelectedCleanupSteps(array $chain, array $variables): array
{
    return array_values(array_filter($chain['cleanup']['steps'] ?? [], static fn (mixed $step): bool => is_array($step) && liveShouldRunStep($step, $variables)));
}

/**
 * @return list<array{cleanup_step_id:string,write_bindings:list<array{step_id:string,capture_ids:list<string>}>,method:string,endpoint:string,capture_ids:list<string>,zero_residual_step_id:string}>
 */
function liveCleanupActionContracts(array $chain): array
{
    $steps = $chain['steps'] ?? [];
    $cleanupSteps = $chain['cleanup']['steps'] ?? [];
    if (!is_array($steps) || !is_array($cleanupSteps) || $cleanupSteps === []) {
        throw new RuntimeException('自动清理契约缺少业务步骤或清理步骤。');
    }

    $writeStepIds = [];
    $captureNamesByWriteStep = [];
    $fixtureCaptureNamesByWriteStep = [];
    $captureProducer = [];
    foreach ($steps as $step) {
        if (!is_array($step)) continue;
        $stepId = (string) ($step['id'] ?? '');
        $stepCaptures = [];
        foreach (($step['capture'] ?? []) as $name => $_source) {
            if (!is_string($name)) continue;
            if (isset($captureProducer[$name])) {
                throw new RuntimeException("业务步骤 {$stepId} 与 {$captureProducer[$name]} 重复声明捕获名称 {$name}；后一步会覆盖前一对象，拒绝执行。");
            }
            $captureProducer[$name] = $stepId;
            $stepCaptures[$name] = true;
        }
        if (($step['write'] ?? null) === true) {
            if (($step['effect'] ?? null) === 'transient_auth_challenge') {
                // A password login with an enabled MFA factor creates an
                // expiring, single-use challenge. It is not a deletion target
                // and must never be treated as a successful bearer session.
                continue;
            }
            $fixtureReferences = $step['fixture_capture_ids'] ?? null;
            if ($fixtureReferences !== null) {
                if (!is_array($fixtureReferences) || !array_is_list($fixtureReferences) || $fixtureReferences === [] || count(array_unique($fixtureReferences)) !== count($fixtureReferences) || array_filter($fixtureReferences, static fn (mixed $name): bool => !is_string($name))) {
                    throw new RuntimeException("写入步骤 {$stepId} 的 fixture_capture_ids 必须是非空捕获名称列表。");
                }
                foreach ($fixtureReferences as $name) {
                    if (!isset($captureProducer[$name]) || !str_ends_with($name, '_id') || preg_match('/(token|secret|password|ticket|code)/i', $name) === 1) {
                        throw new RuntimeException("写入步骤 {$stepId} 引用了尚未产生或不属于夹具对象的捕获名称 {$name}。");
                    }
                    $stepCaptures[$name] = true;
                }
            }
            $writeStepIds[] = $stepId;
            $captureNamesByWriteStep[$stepId] = $stepCaptures;
            $fixtureCaptureNamesByWriteStep[$stepId] = $fixtureReferences === null
                ? array_filter(
                    $stepCaptures,
                    static fn (bool $_present, string $name): bool => str_ends_with($name, '_id') && preg_match('/(token|secret|password|ticket|code)/i', $name) !== 1,
                    ARRAY_FILTER_USE_BOTH,
                )
                : array_fill_keys($fixtureReferences, true);
        }
    }
    if ($writeStepIds === []) throw new RuntimeException('live 链没有可绑定清理动作的写入步骤。');

    $zeroResidualSteps = array_values(array_filter($cleanupSteps, static fn (mixed $step): bool => is_array($step) && ($step['proof'] ?? null) === 'zero_residual'));
    if ($zeroResidualSteps === []) throw new RuntimeException('自动清理契约必须绑定零残留检查。');

    $coveredWriteSteps = [];
    $boundCaptureNamesByWriteStep = [];
    $contracts = [];
    foreach ($cleanupSteps as $step) {
        if (is_array($step) && array_key_exists('capture', $step)) {
            throw new RuntimeException('cleanup step 不得声明 capture；清理只能消费业务阶段捕获值，不能产生或覆盖变量。');
        }
        foreach (['run_if_capture_ids', 'run_unless_capture_ids'] as $field) {
            if (!is_array($step[$field] ?? []) || !array_is_list($step[$field] ?? []) || array_filter($step[$field] ?? [], static fn (mixed $name): bool => !is_string($name) || !isset($captureProducer[$name]))) {
                throw new RuntimeException('cleanup 条件只能引用本链业务阶段已声明的捕获对象。');
            }
        }
    }
    foreach ($cleanupSteps as $step) {
        if (is_array($step) && ($step['proof'] ?? null) === 'zero_residual') continue;
        if (!is_array($step)
            || ($step['proof'] ?? null) !== 'physical_cleanup'
            || ($step['method'] ?? null) !== 'POST'
            || ($step['write'] ?? null) !== true
            || !is_string($step['id'] ?? null)
            || !is_string($step['path'] ?? null)) {
            throw new RuntimeException('每个非零残留 cleanup step 都必须是已声明的 POST 物理清理动作。');
        }
        $cleanupFor = $step['cleanup_for'] ?? null;
        $captureIds = $step['capture_ids'] ?? null;
        if (!is_array($cleanupFor) || $cleanupFor === []) {
            throw new RuntimeException('物理清理动作必须逐项声明它负责清理的写入步骤及对应捕获对象。');
        }
        if (!is_array($captureIds) || $captureIds === [] || array_filter($captureIds, static fn ($id): bool => !is_string($id) || !str_ends_with($id, '_id'))) {
            throw new RuntimeException('物理清理动作必须逐项声明本轮捕获的对象 ID。');
        }
        $referenced = liveReferencedVariables(['path' => $step['path'], 'body' => $step['body'] ?? null]);
        $bindingCaptureIds = [];
        $normalizedBindings = [];
        foreach ($cleanupFor as $binding) {
            if (!is_array($binding) || !is_string($binding['step_id'] ?? null) || !is_array($binding['capture_ids'] ?? null) || $binding['capture_ids'] === []) {
                throw new RuntimeException('物理清理动作的 cleanup_for 必须逐项给出 step_id 与 capture_ids。');
            }
            $writeStepId = $binding['step_id'];
            if (!in_array($writeStepId, $writeStepIds, true)) throw new RuntimeException("物理清理动作绑定了未知写入步骤：{$writeStepId}。");
            foreach ($binding['capture_ids'] as $captureId) {
                if (!is_string($captureId) || !isset($captureNamesByWriteStep[$writeStepId][$captureId])) {
                    throw new RuntimeException("写入步骤 {$writeStepId} 未产生物理清理声明的捕获对象。");
                }
                $bindingCaptureIds[] = $captureId;
                $boundCaptureNamesByWriteStep[$writeStepId][$captureId] = true;
            }
            $normalizedBindings[] = ['step_id' => $writeStepId, 'capture_ids' => array_values($binding['capture_ids'])];
            $coveredWriteSteps[] = $writeStepId;
        }
        $bindingCaptureIds = array_values(array_unique($bindingCaptureIds));
        $declaredCaptureIds = array_values(array_unique($captureIds));
        sort($bindingCaptureIds);
        sort($declaredCaptureIds);
        if ($bindingCaptureIds !== $declaredCaptureIds) {
            throw new RuntimeException('物理清理动作的 capture_ids 与逐写入步骤绑定不一致。');
        }
        foreach ($captureIds as $captureId) {
            if (!in_array($captureId, $referenced, true)) {
                throw new RuntimeException("物理清理动作未实际使用本轮捕获 ID：{$captureId}。");
            }
        }
        $derivedRequestBindings = [];
        foreach (($step['derived_request_bindings'] ?? []) as $binding) {
            if (!is_array($binding) || !is_string($binding['step_id'] ?? null) || !is_string($binding['resource_type'] ?? null) || !is_array($binding['request_ids'] ?? null) || $binding['request_ids'] === []) {
                throw new RuntimeException('派生夹具清理必须声明来源步骤、资源类型和本轮请求编号。');
            }
            $source = array_values(array_filter($steps, static fn (mixed $candidate): bool => is_array($candidate) && ($candidate['id'] ?? null) === $binding['step_id']))[0] ?? null;
            if (!is_array($source) || ($source['effect'] ?? null) !== 'sandiam_invocation_operation' || ($source['request_id'] ?? null) === null || ($binding['resource_type'] ?? null) !== 'service_invocation_operation') {
                throw new RuntimeException('派生调用夹具只能绑定已声明的 SandIAM 调用操作来源。');
            }
            foreach ($binding['request_ids'] as $requestId) {
                if (!is_string($requestId) || $requestId !== $source['request_id'] || !str_contains(json_encode(['path' => $step['path'], 'body' => $step['body'] ?? null], JSON_THROW_ON_ERROR), $requestId)) throw new RuntimeException('派生调用夹具请求编号必须由来源步骤产生并在清理请求中实际使用。');
            }
            $derivedRequestBindings[] = ['step_id' => $binding['step_id'], 'resource_type' => $binding['resource_type'], 'request_ids' => array_values($binding['request_ids'])];
        }
        $zeroResidualStep = null;
        foreach ($zeroResidualSteps as $candidate) {
            if (($candidate['run_if_capture_ids'] ?? []) === ($step['run_if_capture_ids'] ?? []) && ($candidate['run_unless_capture_ids'] ?? []) === ($step['run_unless_capture_ids'] ?? [])) {
                $zeroResidualStep = $candidate;
                break;
            }
        }
        if (!is_array($zeroResidualStep) || !is_string($zeroResidualStep['id'] ?? null)) throw new RuntimeException('每个受控物理清理分支都必须有同条件的零残留检查。');
        $contract = [
            'cleanup_step_id' => $step['id'],
            'write_bindings' => $normalizedBindings,
            'method' => 'POST',
            'endpoint' => $step['path'],
            'capture_ids' => array_values($captureIds),
        ];
        if ($derivedRequestBindings !== []) $contract['derived_request_bindings'] = $derivedRequestBindings;
        if (($step['run_if_capture_ids'] ?? []) !== [] || ($step['run_unless_capture_ids'] ?? []) !== []) {
            $contract['run_if_capture_ids'] = array_values($step['run_if_capture_ids'] ?? []);
            $contract['run_unless_capture_ids'] = array_values($step['run_unless_capture_ids'] ?? []);
        }
        $contract['zero_residual_step_id'] = $zeroResidualStep['id'];
        $contracts[] = $contract;
    }
    sort($writeStepIds);
    $coveredWriteSteps = array_values(array_unique($coveredWriteSteps));
    sort($coveredWriteSteps);
    if ($contracts === [] || $coveredWriteSteps !== $writeStepIds) {
        throw new RuntimeException('自动清理动作没有逐一覆盖本链全部创建/写入步骤。');
    }
    foreach ($fixtureCaptureNamesByWriteStep as $writeStepId => $fixtureCaptures) {
        $expected = array_keys($fixtureCaptures);
        $bound = array_keys(array_intersect_key($boundCaptureNamesByWriteStep[$writeStepId] ?? [], $fixtureCaptures));
        sort($expected);
        sort($bound);
        if ($bound !== $expected) {
            throw new RuntimeException("写入步骤 {$writeStepId} 产生的本轮夹具对象 ID 未被清理动作完整覆盖。");
        }
    }
    return $contracts;
}

/** @return array<string,mixed> */
function livePlan(): array
{
    $file = liveRequire('SAND_IAM_ACCEPTANCE_LIVE_PLAN');
    if (!is_file($file) || !is_readable($file)) throw new RuntimeException('SAND_IAM_ACCEPTANCE_LIVE_PLAN 必须指向可读取的 JSON 文件。');
    $plan = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($plan) || ($plan['format'] ?? null) !== 'sand-iam.acceptance-live-plan/v2' || !is_array($plan['targets'] ?? null) || !is_array($plan['chains'] ?? null)) {
        throw new RuntimeException('live plan 格式必须是 sand-iam.acceptance-live-plan/v2，且含 targets/chains。');
    }
    if (str_contains(json_encode($plan, JSON_THROW_ON_ERROR), '__REQUIRED_')) throw new RuntimeException('live plan 仍含 __REQUIRED_* 占位符，拒绝向宿主发送不完整请求。');
    return $plan;
}

function livePreflightGate(array $plan, string $prefix, string $chainId, array $chain): void
{
    $gate = $plan['preflight'] ?? null;
    if (!is_array($gate) || !is_array($gate['fixture_ownership'] ?? null) || !is_array($gate['physical_cleanup'] ?? null)) {
        throw new RuntimeException('live plan 缺少结构化 fixture_ownership/physical_cleanup preflight。');
    }
    $declaredPrefix = $gate['fixture_ownership']['prefix'] ?? null;
    if (!in_array($declaredPrefix, [$prefix, '${prefix}'], true) || ($gate['fixture_ownership']['confirmation'] ?? null) !== 'I_OWN_THIS_PREFIXED_FIXTURE_SCOPE' || ($gate['physical_cleanup']['confirmation'] ?? null) !== 'I_HAVE_VERIFIED_AUTOMATED_CLEANUP') {
        throw new RuntimeException('live plan 的夹具归属或物理清理确认与本轮前缀不匹配。');
    }
    if (getenv('SAND_IAM_ACCEPTANCE_FIXTURE_OWNERSHIP_CONFIRM') !== 'I_OWN_THIS_PREFIXED_FIXTURE_SCOPE' || getenv('SAND_IAM_ACCEPTANCE_AUTOMATED_CLEANUP_CONFIRM') !== 'I_HAVE_VERIFIED_AUTOMATED_CLEANUP') {
        throw new RuntimeException('任何 HTTP 前必须确认夹具归属和本链可自动完成物理清理。');
    }
    $evidence = trim((string) getenv('SAND_IAM_ACCEPTANCE_OWNERSHIP_EVIDENCE'));
    if ($evidence === '' || !str_contains($evidence, $prefix)) throw new RuntimeException('夹具归属证据必须包含本轮固定前缀。');
    $cleanupEvidence = $gate['physical_cleanup']['evidence']['chains'][$chainId] ?? null;
    $cleanupSteps = $chain['cleanup']['steps'] ?? [];
    $cleanupStepIds = is_array($cleanupSteps) ? array_values(array_map(static fn (array $step): string => (string) ($step['id'] ?? ''), $cleanupSteps)) : [];
    $actionContracts = liveCleanupActionContracts($chain);
    $selectedCleanupSteps = liveSelectedCleanupSteps($chain, liveFullSuccessVariables($chain));
    $selectedCleanupStepIds = array_values(array_map(static fn (array $step): string => (string) ($step['id'] ?? ''), $selectedCleanupSteps));
    $selectedZeroResidualSteps = array_values(array_filter($selectedCleanupSteps, static fn (array $step): bool => ($step['proof'] ?? null) === 'zero_residual'));
    $selectedZeroResidualStepIds = array_values(array_map(static fn (array $step): string => (string) ($step['id'] ?? ''), $selectedZeroResidualSteps));
    $selectedPhysicalStepIds = array_values(array_map(static fn (array $step): string => (string) ($step['id'] ?? ''), array_filter($selectedCleanupSteps, static fn (array $step): bool => ($step['proof'] ?? null) === 'physical_cleanup')));
    $selectedContractZeroIds = [];
    foreach ($actionContracts as $contract) {
        if (in_array($contract['cleanup_step_id'] ?? null, $selectedPhysicalStepIds, true)) $selectedContractZeroIds[] = $contract['zero_residual_step_id'] ?? null;
    }
    $normalizedSelectedContractZeroIds = array_values(array_unique($selectedContractZeroIds));
    $normalizedSelectedZeroResidualStepIds = $selectedZeroResidualStepIds;
    sort($normalizedSelectedContractZeroIds);
    sort($normalizedSelectedZeroResidualStepIds);
    $allContractZeroIds = array_values(array_unique(array_map(
        static fn (array $contract): mixed => $contract['zero_residual_step_id'] ?? null,
        $actionContracts,
    )));
    $evidenceZeroResidualStepIds = $cleanupEvidence['zero_residual_step_ids'] ?? null;
    if ($evidenceZeroResidualStepIds === null && is_string($cleanupEvidence['zero_residual_step_id'] ?? null)) {
        $evidenceZeroResidualStepIds = [$cleanupEvidence['zero_residual_step_id']];
    }
    if (!is_array($cleanupEvidence)
        || ($cleanupEvidence['api_available'] ?? null) !== true
        || ($cleanupEvidence['cleanup_step_ids'] ?? null) !== $cleanupStepIds
        || ($cleanupEvidence['action_contracts'] ?? null) !== $actionContracts
        || $selectedZeroResidualStepIds === []
        || !is_array($evidenceZeroResidualStepIds)
        || array_diff($selectedZeroResidualStepIds, $evidenceZeroResidualStepIds) !== []
        || array_diff($evidenceZeroResidualStepIds, $allContractZeroIds) !== []
        || $normalizedSelectedContractZeroIds !== $normalizedSelectedZeroResidualStepIds
        || array_filter($evidenceZeroResidualStepIds, static fn (string $id): bool => !in_array($id, $cleanupStepIds, true)) !== []) {
        throw new RuntimeException('自动清理证据为空、未逐项绑定真实清理动作、与所选业务链不匹配，或未绑定最终零残留检查。');
    }
    $runtimeCleanupEvidence = trim((string) getenv('SAND_IAM_ACCEPTANCE_AUTOMATED_CLEANUP_EVIDENCE'));
    if ($runtimeCleanupEvidence === '' || !str_contains($runtimeCleanupEvidence, $prefix) || !str_contains($runtimeCleanupEvidence, $chainId)) {
        throw new RuntimeException('运行前自动清理证据必须同时包含本轮前缀和所选业务链。');
    }
}

/** @return array<string,string> */
function liveAuthorizations(array $slots): array
{
    $environment = [
        'platform_admin' => 'SAND_IAM_ACCEPTANCE_PLATFORM_ADMIN_AUTHORIZATION',
        'scoped_admin' => 'SAND_IAM_ACCEPTANCE_SCOPED_ADMIN_AUTHORIZATION',
        'out_of_scope_admin' => 'SAND_IAM_ACCEPTANCE_OUT_OF_SCOPE_ADMIN_AUTHORIZATION',
        'application_user' => 'SAND_IAM_ACCEPTANCE_APPLICATION_USER_AUTHORIZATION',
        'service_client' => 'SAND_IAM_ACCEPTANCE_SERVICE_CLIENT_AUTHORIZATION',
    ];
    $authorizations = [];
    foreach ($slots as $slot) {
        if (!isset($environment[$slot])) throw new RuntimeException("不支持的凭证槽：{$slot}。");
        $value = liveRequire($environment[$slot]);
        if (!preg_match('/^[A-Za-z-]+:\s*[^\r\n]+$/', $value)) throw new RuntimeException("凭证槽 {$slot} 必须是完整的单行 HTTP 头。");
        $authorizations[$slot] = $value;
    }
    return $authorizations;
}

/** @return array{base_url:string,allow_paths:list<string>,kind:string} */
function liveSafeTarget(array $target, string $defaultHost): array
{
    $baseUrl = rtrim((string) ($target['base_url'] ?? $defaultHost), '/');
    $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
    if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || ($scheme !== 'https' && getenv('SAND_IAM_ACCEPTANCE_ALLOW_HTTP') !== '1')) {
        throw new RuntimeException('target base_url 必须为 HTTPS；仅本地受控测试可额外允许 HTTP。');
    }
    $paths = $target['allow_paths'] ?? null;
    if (!is_array($paths) || $paths === [] || array_filter($paths, static fn ($path): bool => !is_string($path) || !str_starts_with($path, '/'))) {
        throw new RuntimeException('每个 target 必须给出非空的绝对路径 allow_paths。');
    }
    $kind = (string) ($target['kind'] ?? 'external');
    if (!in_array($kind, ['sandiam', 'business_app', 'external'], true)) throw new RuntimeException('target kind 只能是 sandiam、business_app 或 external。');
    return ['base_url' => $baseUrl, 'allow_paths' => array_values($paths), 'kind' => $kind];
}

function liveAllowedPath(string $path, array $target): bool
{
    if ($path === '' || !str_starts_with($path, '/') || str_contains($path, "\r") || str_contains($path, "\n") || str_contains($path, '://')) return false;
    $actual = (string) parse_url($path, PHP_URL_PATH);
    foreach ($target['allow_paths'] as $allowed) if ($actual === $allowed || str_starts_with($actual, rtrim($allowed, '/') . '/')) return true;
    return false;
}

/** @return array{fields:array<string,string>,file:array{field:string,filename:string,content_type:string,content:string}}|null */
function liveMultipartDefinition(array $target, array $step): ?array
{
    $multipart = $step['multipart'] ?? null;
    if ($multipart === null) return null;
    $fields = is_array($multipart) ? ($multipart['fields'] ?? null) : null;
    $file = is_array($multipart) ? ($multipart['file'] ?? null) : null;
    if (($target['kind'] ?? null) !== 'sandiam'
        || ($step['method'] ?? null) !== 'POST'
        || ($step['write'] ?? null) !== true
        || ($step['path'] ?? null) !== '/app/sand-iam/admin/identity-import/preview'
        || array_key_exists('body', $step)
        || !is_array($fields)
        || array_keys($fields) !== ['application_id', 'mode']
        || !is_array($file)
        || array_keys($file) !== ['field', 'filename', 'content_type', 'content']
        || ($file['field'] ?? null) !== 'file'
        || ($file['content_type'] ?? null) !== 'text/csv'
        || !is_string($file['filename'] ?? null)
        || strlen($file['filename']) < 5
        || strlen($file['filename']) > 255
        || !str_ends_with(strtolower($file['filename']), '.csv')
        || preg_match('#[\\\\/\\x00-\\x1f\\x7f]#', $file['filename'])
        || !is_string($file['content'] ?? null)
        || $file['content'] === ''
        || strlen($file['content']) > 65_536
        || str_contains($file['content'], "\0")) {
        throw new RuntimeException('multipart 只允许 SandIAM 身份导入预检使用一个不超过 64KiB 的内联 CSV 文件。');
    }
    foreach ($fields as $name => $value) {
        if (!is_string($name) || !is_string($value) || $value === '' || strlen($value) > 128 || preg_match('/[\\x00-\\x1f\\x7f]/', $value)) {
            throw new RuntimeException('multipart 表单字段不安全。');
        }
    }
    return ['fields' => $fields, 'file' => $file];
}

function liveMediaType(array $target, array $step): string
{
    $mediaType = $step['media_type'] ?? 'application/json';
    if (!is_string($mediaType)) throw new RuntimeException('media_type 必须是字符串。');
    if ($mediaType === 'application/json') return $mediaType;
    if ($mediaType !== 'application/scim+json'
        || ($target['kind'] ?? null) !== 'sandiam'
        || !str_starts_with((string) ($step['path'] ?? ''), '/api/sand-iam/v1/scim/')) {
        throw new RuntimeException('application/scim+json 只允许用于 SandIAM SCIM 运行端点。');
    }
    return $mediaType;
}

function liveQueryString(array $step): string
{
    $query = $step['query'] ?? null;
    if ($query === null) return '';
    if (($step['method'] ?? null) !== 'GET' || !is_array($query) || $query === []) {
        throw new RuntimeException('query 只允许用于 GET，且必须是非空对象。');
    }
    $validate = static function (mixed $value, int $depth = 0) use (&$validate): void {
        if ($depth > 4) throw new RuntimeException('query 嵌套过深。');
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if ((!is_int($key) && (!is_string($key) || preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $key) !== 1))) {
                    throw new RuntimeException('query 字段名不安全。');
                }
                $validate($item, $depth + 1);
            }
            return;
        }
        if (!is_scalar($value) || (is_string($value) && (strlen($value) > 512 || preg_match('/[\x00-\x1f\x7f]/', $value)))) {
            throw new RuntimeException('query 值不安全。');
        }
    };
    $validate($query);
    $encoded = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    if ($encoded === '' || strlen($encoded) > 8192) throw new RuntimeException('query 编码结果为空或过长。');
    return $encoded;
}

function liveDeclaredRequestId(array $step, string $fallback): string
{
    if (is_string($step['request_id'] ?? null) && $step['request_id'] !== '') return $step['request_id'];
    $query = $step['query'] ?? null;
    if (is_array($query) && is_string($query['request_id'] ?? null) && $query['request_id'] !== '') {
        return $query['request_id'];
    }
    parse_str((string) parse_url((string) ($step['path'] ?? ''), PHP_URL_QUERY), $pathQuery);
    return is_string($pathQuery['request_id'] ?? null) && $pathQuery['request_id'] !== ''
        ? $pathQuery['request_id']
        : $fallback;
}

function liveExtraHeadersSafe(mixed $headers): bool
{
    if (!is_array($headers)) return false;
    foreach ($headers as $name => $value) {
        if (!is_string($name)
            || !is_string($value)
            || preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/", $name) !== 1
            || preg_match('/[\r\n]/', $value)) {
            return false;
        }
    }
    return true;
}

function liveCustomCaFile(array $target): ?string
{
    if (parse_url((string) ($target['base_url'] ?? ''), PHP_URL_SCHEME) !== 'https') return null;
    $configured = getenv('SAND_IAM_ACCEPTANCE_CA_FILE');
    if (!is_string($configured) || $configured === '') return null;
    if (getenv('SAND_IAM_ACCEPTANCE_ALLOW_CUSTOM_CA') !== 'I_UNDERSTAND_THIS_TRUSTS_ONLY_THE_CONFIGURED_CA') {
        throw new RuntimeException('自定义验收 CA 必须显式确认。');
    }
    $real = realpath($configured);
    if ($real === false || !is_file($real) || !is_readable($real)) {
        throw new RuntimeException('自定义验收 CA 文件不可读。');
    }
    return $real;
}

/** @return array{status:int,body:string,json:array<string,mixed>|null,headers:array<string,list<string>>,location:string} */
function liveHttp(array $target, array $step, string $authorization, string $requestId, ?string $cookieJar): array
{
    $declaredRequestId = liveDeclaredRequestId($step, $requestId);
    if (preg_match(SAND_IAM_ACCEPTANCE_FIXTURE_REQUEST_ID_PATTERN, $declaredRequestId) !== 1) throw new RuntimeException('计划中的 request_id 必须以完整验收前缀开头且使用安全后缀。');
    $transport = $GLOBALS['sand_iam_live_http_transport'] ?? null;
    if (is_callable($transport)) {
        $response = $transport($target, $step, $authorization, $declaredRequestId, $cookieJar);
        if (!is_array($response) || !isset($response['status'], $response['body'], $response['headers'], $response['location'])) throw new RuntimeException('测试 transport 返回的响应格式无效。');
        $response['json'] ??= json_decode((string) $response['body'], true);
        return $response;
    }
    if (!function_exists('curl_init')) throw new RuntimeException('当前 PHP 缺少 curl，不能执行 live HTTP 验收。');
    $method = strtoupper((string) ($step['method'] ?? ''));
    $path = (string) ($step['path'] ?? '');
    $query = liveQueryString($step);
    $write = $step['write'] ?? null;
    if (!liveMethodWriteAllowed($target, $step) || !liveAllowedPath($path, $target)) {
        throw new RuntimeException('live step 的 method/write/path 不符合受控 allowlist。');
    }
    $body = $step['body'] ?? null;
    $multipart = liveMultipartDefinition($target, $step);
    $mediaType = liveMediaType($target, $step);
    if ((in_array($method, ['POST', 'PATCH'], true) && $multipart === null && !is_array($body))
        || (in_array($method, ['GET', 'DELETE'], true) && ($body !== null || $multipart !== null))) {
        throw new RuntimeException('POST/PATCH 必须是 JSON 对象或登记的内联 multipart，GET/DELETE 不得带请求体。');
    }
    $extraHeaders = $step['headers'] ?? [];
    if (!liveExtraHeadersSafe($extraHeaders)) throw new RuntimeException('请求头不安全。');
    foreach ($extraHeaders as $name => $_value) {
        if (strcasecmp($name, 'Authorization') === 0 || strcasecmp($name, 'X-Request-Id') === 0 || strcasecmp($name, 'Content-Type') === 0) throw new RuntimeException('计划不得重复或自行注入 Authorization/X-Request-Id/Content-Type。');
    }
    if ($target['kind'] === 'external' && $authorization !== '') throw new RuntimeException('外部 target 不得携带 SandIAM 凭证。');
    $headers = ['Accept: ' . $mediaType, 'X-Request-Id: ' . $declaredRequestId];
    if ($multipart === null && $body !== null) $headers[] = 'Content-Type: ' . $mediaType;
    if (in_array($target['kind'], ['sandiam', 'business_app'], true) && $authorization !== '') $headers[] = $authorization;
    foreach ($extraHeaders as $name => $value) $headers[] = $name . ': ' . $value;
    $responseHeaders = [];
    $curl = curl_init($target['base_url'] . $path . ($query === '' ? '' : (str_contains($path, '?') ? '&' : '?') . $query));
    if ($curl === false) throw new RuntimeException('无法初始化 HTTP 请求。');
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
            if (str_contains($line, ':')) { [$name, $value] = explode(':', $line, 2); $responseHeaders[strtolower(trim($name))][] = trim($value); }
            return strlen($line);
        },
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if (($caFile = liveCustomCaFile($target)) !== null) curl_setopt($curl, CURLOPT_CAINFO, $caFile);
    if ($target['kind'] === 'sandiam' && $cookieJar !== null) {
        curl_setopt($curl, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($curl, CURLOPT_COOKIEJAR, $cookieJar);
    }
    if ($multipart !== null) {
        if (!class_exists('CURLStringFile')) throw new RuntimeException('当前 PHP curl 不支持安全的内联 multipart 文件。');
        $postFields = $multipart['fields'];
        $postFields[$multipart['file']['field']] = new CURLStringFile(
            $multipart['file']['content'],
            $multipart['file']['filename'],
            $multipart['file']['content_type'],
        );
        curl_setopt($curl, CURLOPT_POSTFIELDS, $postFields);
    } elseif ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($response === false) throw new RuntimeException('HTTP 请求失败：' . $error);
    $decoded = json_decode((string) $response, true);
    $location = (string) (($responseHeaders['location'][0] ?? ''));
    return ['status' => $status, 'body' => (string) $response, 'json' => is_array($decoded) ? $decoded : null, 'headers' => $responseHeaders, 'location' => $location];
}

function liveInterpolate(mixed $value, array $variables): mixed
{
    if (is_array($value)) { foreach ($value as $key => $item) $value[$key] = liveInterpolate($item, $variables); return $value; }
    if (!is_string($value)) return $value;
    return preg_replace_callback('/\$\{([a-zA-Z0-9_.-]+)\}/', static function (array $match) use ($variables): string {
        if (!array_key_exists($match[1], $variables)) throw new RuntimeException("live plan 引用了未知变量：{$match[1]}。");
        return (string) $variables[$match[1]];
    }, $value) ?? $value;
}

function liveTotpCode(string $base32Secret, ?int $afterCounter = null): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = strtoupper(str_replace([' ', '-'], '', trim($base32Secret)));
    if ($secret === '' || preg_match('/^[A-Z2-7]+$/', $secret) !== 1) throw new RuntimeException('TOTP 密钥格式无效。');
    $buffer = 0;
    $bits = 0;
    $raw = '';
    foreach (str_split($secret) as $character) {
        $position = strpos($alphabet, $character);
        if ($position === false) throw new RuntimeException('TOTP 密钥格式无效。');
        $buffer = ($buffer << 5) | $position;
        $bits += 5;
        while ($bits >= 8) {
            $bits -= 8;
            $raw .= chr(($buffer >> $bits) & 0xff);
        }
    }
    if (strlen($raw) < 10) throw new RuntimeException('TOTP 密钥长度不足。');
    $counter = intdiv(time(), 30);
    if ($afterCounter !== null) {
        while ($counter <= $afterCounter) {
            usleep(250000);
            $counter = intdiv(time(), 30);
        }
    }
    $hash = hash_hmac('sha1', pack('N2', 0, $counter), $raw, true);
    $offset = ord($hash[19]) & 0x0f;
    $value = ((ord($hash[$offset]) & 0x7f) << 24)
        | (ord($hash[$offset + 1]) << 16)
        | (ord($hash[$offset + 2]) << 8)
        | ord($hash[$offset + 3]);
    return str_pad((string) ($value % 1_000_000), 6, '0', STR_PAD_LEFT);
}

function liveCaptureNameSensitive(string $name): bool
{
    if (str_ends_with($name, '_ref')) return false;
    return preg_match('/(?:token|secret|password|ticket)/i', $name) === 1
        || preg_match('/(?:^|_)(?:oauth|authorization|totp|recovery|verification|challenge)_code(?:$|_)/i', $name) === 1;
}

/** @param array<string,mixed> $step @param array<string,mixed> $variables */
function liveDeriveBody(array $step, array $variables): array
{
    $body = $step['body'] ?? null;
    if (!is_array($body)) return $step;
    foreach (($step['body_derivations'] ?? []) as $field => $definition) {
        $derive = $definition['derive'] ?? null;
        if (!is_string($field) || !is_array($definition) || !in_array($derive, ['totp_sha1_6', 'totp_sha1_6_next'], true) || !is_string($definition['from_capture'] ?? null)) {
            throw new RuntimeException('body_derivations 只允许从本轮 TOTP 密钥动态生成六位验证码。');
        }
        $secret = $variables[$definition['from_capture']] ?? null;
        if (!is_string($secret) || $secret === '') throw new RuntimeException('body_derivations 引用的 TOTP 密钥尚未捕获。');
        $counterKey = '__totp_counter_' . hash('sha256', $secret);
        $lastCounter = $variables[$counterKey] ?? null;
        $body[$field] = liveTotpCode($secret, $derive === 'totp_sha1_6_next' && is_int($lastCounter) ? $lastCounter : null);
        $variables[$counterKey] = intdiv(time(), 30);
    }
    $step['body'] = $body;
    return $step;
}

function liveJsonPath(mixed $value, string $path): mixed
{
    foreach (explode('.', $path) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) throw new RuntimeException("响应缺少断言路径：{$path}。");
        $value = $value[$part];
    }
    return $value;
}

function liveMatches(mixed $actual, mixed $expected): bool
{
    if ((is_int($actual) || is_float($actual)) && is_string($expected) && preg_match('/^-?\d+(?:\.\d+)?$/', $expected) === 1) {
        return (string) $actual === $expected;
    }
    if (!is_array($expected) || array_is_list($expected)) return $actual === $expected;
    if (array_key_exists('equals', $expected)) return $actual === $expected['equals'];
    if (($expected['exists'] ?? null) === true) return $actual !== null && $actual !== '';
    if (isset($expected['contains'])) {
        if (is_string($actual)) return str_contains($actual, (string) $expected['contains']);
        if (is_array($actual) && is_array($expected['contains'])) {
            foreach ($expected['contains'] as $key => $value) if (!array_key_exists($key, $actual) || !liveMatches($actual[$key], $value)) return false;
            return true;
        }
        return is_array($actual) && in_array($expected['contains'], $actual, true);
    }
    if (isset($expected['matches']) && is_string($actual)) return @preg_match((string) $expected['matches'], $actual) === 1;
    return false;
}

function liveAssertionValue(mixed $value): string
{
    if (is_bool($value)) return $value ? 'true' : 'false';
    if ($value === null) return 'null';
    if (is_int($value) || is_float($value)) return (string) $value;
    if (!is_string($value)) return get_debug_type($value);
    $value = preg_replace('/[\x00-\x1f\x7f]+/', ' ', $value) ?? '';
    return mb_strlen($value) > 96 ? mb_substr($value, 0, 96) . '…' : $value;
}

/** @return array{ok:bool,detail:string} */
function liveCheckResponse(array $response, array $step): array
{
    $expected = $step['expect_http'] ?? null;
    $expected = is_array($expected) ? $expected : [$expected];
    if ($expected === [null] || !in_array($response['status'], array_map('intval', $expected), true)) {
        $declared = implode('/', array_map('strval', $expected));
        $applicationCode = '';
        if (is_array($response['json'] ?? null)) {
            if (is_int($response['json']['code'] ?? null)) {
                $applicationCode .= '，响应 code=' . $response['json']['code'];
            }
            $message = $response['json']['msg'] ?? $response['json']['message'] ?? $response['json']['error'] ?? null;
            if (is_string($message) && preg_match('/^[A-Za-z0-9_.:-]{1,128}/', $message, $match) === 1) {
                $applicationCode .= '，错误=' . $match[0];
                if (preg_match('/（([a-z_]+)）/u', $message, $typeMatch) === 1) {
                    $applicationCode .= '，对象类型=' . $typeMatch[1];
                }
            }
        }
        return ['ok' => false, 'detail' => "HTTP 状态不符合计划断言：实际 {$response['status']}{$applicationCode}，期望 {$declared}。"];
    }
    $assert = $step['assert'] ?? null;
    if (!is_array($assert) || $assert === []) return ['ok' => false, 'detail' => '缺少业务语义断言。'];
    foreach (($assert['json'] ?? []) as $path => $expectedValue) {
        try {
            $actualValue = liveJsonPath($response['json'], (string) $path);
            if (!liveMatches($actualValue, $expectedValue)) {
                $errorCode = '';
                $message = is_array($response['json'] ?? null)
                    ? ($response['json']['msg'] ?? $response['json']['message'] ?? null)
                    : null;
                if (is_string($message) && preg_match('/^(SAND_IAM_[A-Z0-9_]+)/', $message, $match) === 1) {
                    $errorCode = '，业务错误码 ' . $match[1];
                    if (preg_match('/（([a-z_]+)）/u', $message, $typeMatch) === 1) {
                        $errorCode .= '，对象类型 ' . $typeMatch[1];
                    }
                }
                return ['ok' => false, 'detail' => "JSON 断言失败：{$path}，实际 " . liveAssertionValue($actualValue) . $errorCode . '。'];
            }
        } catch (Throwable) {
            return ['ok' => false, 'detail' => "JSON 断言失败：{$path}，响应缺少该路径。"];
        }
    }
    foreach (($assert['headers'] ?? []) as $name => $expectedValue) {
        $actual = implode(', ', $response['headers'][strtolower((string) $name)] ?? []);
        if (!liveMatches($actual, $expectedValue)) return ['ok' => false, 'detail' => "响应头断言失败：{$name}。"];
    }
    if (isset($assert['location']) && !liveMatches($response['location'], $assert['location'])) return ['ok' => false, 'detail' => 'Location 断言失败。'];
    if (isset($assert['body']) && !liveMatches($response['body'], $assert['body'])) return ['ok' => false, 'detail' => '响应体断言失败。'];
    if (isset($assert['json_contains'])) foreach ($assert['json_contains'] as $path => $expectedValue) {
        try { $actual = liveJsonPath($response['json'], (string) $path); } catch (Throwable) { return ['ok' => false, 'detail' => "JSON 包含断言失败：{$path}。"]; }
        if (!is_array($actual) || !array_filter($actual, static fn ($item): bool => liveMatches($item, $expectedValue))) return ['ok' => false, 'detail' => "JSON 包含断言失败：{$path}。"];
    }
    return ['ok' => true, 'detail' => 'HTTP 与声明的业务语义均符合。'];
}

function liveValidateChainPlan(string $chainId, array $chain, array $credentialRequirements, array $targets): void
{
    $requiredSlots = $chain['required_credentials'] ?? null;
    if (!is_array($requiredSlots) || array_values($requiredSlots) !== $credentialRequirements[$chainId]) throw new RuntimeException('live 链的凭证槽不完整或不符合该链角色边界。');
    if (!is_string($chain['required_fixture_gate'] ?? null) || trim($chain['required_fixture_gate']) === '') throw new RuntimeException('每条 live 链必须明确 required_fixture_gate，不能把预置夹具写成可自动完成。');
    if (!is_string($chain['physical_cleanup_gate'] ?? null) || trim($chain['physical_cleanup_gate']) === '') throw new RuntimeException('每条 live 链必须明确 physical_cleanup_gate。');
    if (!is_array($chain['steps'] ?? null) || !is_array($chain['cleanup'] ?? null) || !is_array($chain['cleanup']['steps'] ?? null)) throw new RuntimeException('每条 live 链必须有 steps 与 cleanup.steps。');
    $captureProducers = [];
    foreach ($chain['steps'] as $index => $candidate) {
        if (!is_array($candidate)) continue;
        foreach (($candidate['capture'] ?? []) as $name => $source) {
            if (!is_string($name) || !is_array($source)) throw new RuntimeException('live chain 的 capture 名称和来源必须完整。');
            if (isset($captureProducers[$name])) throw new RuntimeException("业务步骤重复声明捕获名称 {$name}。");
            $captureProducers[$name] = ['index' => $index, 'source' => $source];
        }
    }
    $proofs = [];
    foreach ($chain['steps'] as $index => $step) {
        if (!is_array($step) || !is_string($step['id'] ?? null) || !is_string($step['proof'] ?? null) || !is_string($step['target'] ?? null) || !isset($targets[$step['target']]) || !is_array($step['assert'] ?? null) || ($step['assert'] ?? []) === []) throw new RuntimeException('live step 缺少 id/proof/target/assert，或 target 不在 allowlist。');
        if (!liveMethodWriteAllowed($targets[$step['target']], $step)) throw new RuntimeException('live step 的 method/write/effect 不一致。');
        liveMultipartDefinition($targets[$step['target']], $step);
        liveMediaType($targets[$step['target']], $step);
        livePollDefinition($step);
        $auth = (string) ($step['auth'] ?? '');
        $targetKind = (string) ($targets[$step['target']]['kind'] ?? '');
        $authCapture = $step['auth_capture'] ?? null;
        if ($authCapture !== null) {
            $producer = is_string($authCapture) ? ($captureProducers[$authCapture] ?? null) : null;
            $capturedScimToken = $auth === 'scim_token'
                && $targetKind === 'sandiam'
                && str_starts_with((string) ($step['path'] ?? ''), '/api/sand-iam/v1/scim/');
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', (string) $authCapture)
                || !is_array($producer)
                || ($producer['index'] ?? PHP_INT_MAX) >= $index
                || (($producer['source']['sensitive'] ?? false) !== true)
                || ($auth !== 'application_user' && !$capturedScimToken)) {
                throw new RuntimeException('auth_capture 必须引用此前响应捕获的敏感应用用户或 SCIM token。');
            }
        }
        if (array_key_exists('session_scope', $step)) throw new RuntimeException('session_scope 不允许由计划自定义；会话隔离范围只能由当前凭证槽或本轮捕获凭证确定。');
        if ($targetKind === 'external') {
            if ($auth !== 'none') throw new RuntimeException('外部 target 必须声明 auth:none，且不得携带 SandIAM 凭证。');
        } elseif ($targetKind === 'business_app') {
            if ($auth !== 'application_user' || $authCapture !== null) {
                throw new RuntimeException('业务应用 target 只能使用本链明确批准的 application_user 凭证槽。');
            }
        } elseif ($authCapture === null && $auth !== 'none' && !in_array($auth, $requiredSlots, true)) {
            throw new RuntimeException('step 使用了未获该链批准的凭证槽。');
        }
        $proofs[$step['proof']] = true;
    }
    foreach (['create', 'allow', 'deny', 'audit', 'revoke_or_recover', 'revoke_effective'] as $required) if (!isset($proofs[$required])) throw new RuntimeException("live 链缺少 {$required} 证明步骤，拒绝降级通过。");
    $cleanupSteps = $chain['cleanup']['steps'];
    $cleanupProof = false;
    foreach ($cleanupSteps as $index => $step) {
        if (!is_array($step) || !is_string($step['id'] ?? null)) throw new RuntimeException('cleanup step 缺少 id。');
        $readonlyVerifier = $step['verifier'] ?? null;
        if (is_array($readonlyVerifier) && ($readonlyVerifier['kind'] ?? null) === 'postgres_readonly') {
            if (($step['proof'] ?? '') !== 'zero_residual' || !is_string($readonlyVerifier['check'] ?? null) || !isset(livePostgresReadonlyChecks()[$readonlyVerifier['check']])) throw new RuntimeException('postgres_readonly 必须是已登记的零残留检查。');
            $cleanupProof = true;
            continue;
        }
        if (!is_string($step['target'] ?? null) || !isset($targets[$step['target']]) || !is_array($step['assert'] ?? null) || ($step['assert'] ?? []) === []) throw new RuntimeException('cleanup step 缺少 target/assert。');
        $auth = (string) ($step['auth'] ?? '');
        $targetKind = (string) ($targets[$step['target']]['kind'] ?? '');
        if ($targetKind === 'external') {
            if ($auth !== 'none') throw new RuntimeException('外部 cleanup target 必须声明 auth:none。');
        } elseif ($targetKind === 'business_app') {
            if ($auth !== 'application_user' || isset($step['auth_capture'])) {
                throw new RuntimeException('业务应用 cleanup target 只能使用本链明确批准的 application_user 凭证槽。');
            }
        } elseif (isset($step['auth_capture'])) {
            $producer = is_string($step['auth_capture']) ? ($captureProducers[$step['auth_capture']] ?? null) : null;
            if (!is_array($producer) || (($producer['source']['sensitive'] ?? false) !== true) || $auth !== 'application_user') {
                throw new RuntimeException('cleanup step 的 auth_capture 必须引用本轮捕获的敏感应用用户 token。');
            }
        } elseif (!in_array($auth, $requiredSlots, true)) {
            throw new RuntimeException('cleanup step 使用了未获该链批准的凭证槽。');
        }
        if (($step['proof'] ?? '') === 'zero_residual') {
            if (($step['method'] ?? null) !== 'GET' || ($step['write'] ?? null) !== false) throw new RuntimeException('零残留查询必须是只读步骤。');
            $cleanupProof = true;
        }
    }
    if (!$cleanupProof) throw new RuntimeException('cleanup 必须以零残留查询断言结束。');
    if (!is_bool($chain['cleanup']['api_available'] ?? null)) throw new RuntimeException('cleanup 必须明确 api_available。');
    if (($chain['cleanup']['api_available'] ?? null) === false) {
        if (!is_array($chain['manual_sql_cleanup'] ?? null) || $chain['manual_sql_cleanup'] === []) throw new RuntimeException('没有 API 清理能力时必须声明精确 manual_sql_cleanup。');
        foreach ($chain['manual_sql_cleanup'] as $target) {
            if (!is_array($target) || preg_match('/^sand_iam_[a-z0-9_]+$/', (string) ($target['table'] ?? '')) !== 1 || !is_array($target['where'] ?? null) || $target['where'] === [] || !is_string($target['reason'] ?? null)) throw new RuntimeException('manual_sql_cleanup 必须逐项给出 sand_iam_* 表、精确 where 和原因。');
        }
        throw new RuntimeException('写入前阻断：本链没有可自动完成的物理清理接口。manual_sql_cleanup 仅用于说明缺口；补齐受控清理 API 和零残留断言前，不会向宿主发送任何请求。');
    } elseif (isset($chain['manual_sql_cleanup']) && $chain['manual_sql_cleanup'] !== []) {
        throw new RuntimeException('存在 API 清理能力时不得把 manual_sql_cleanup 当作必经步骤。');
    }
    liveCleanupActionContracts($chain);
    foreach ($cleanupSteps as $step) {
        $path = (string) ($step['path'] ?? '');
        if (($step['proof'] ?? '') === 'zero_residual' && (str_contains($path, 'status=1') || str_contains($path, 'status%3D1'))) throw new RuntimeException('zero_residual 不得只查询启用状态；物理清理后必须全状态精确查询。');
    }
    if ($chainId === 'human-auth-session-mfa') liveValidateHumanAuthSessionMfaProtocol($chain);
    if ($chainId === 'identity-group-role-policy') liveValidateIdentityDirectoryProtocol($chain, $targets);
    if ($chainId === 'oauth-cas-api-governance') liveValidateOAuthCasApiGovernanceProtocol($chain, $targets);
    if ($chainId === 'delegation-scope') liveValidateDelegationScopeProtocol($chain);
    if ($chainId === 'event-webhook-delivery') liveValidateWebhookDeliveryProtocol($chain, $targets);
}

/** @param array<string,array<string,mixed>> $targets */
function liveValidateIdentityDirectoryProtocol(array $chain, array $targets): void
{
    $byId = [];
    foreach ($chain['steps'] ?? [] as $index => $step) {
        if (is_array($step) && is_string($step['id'] ?? null)) $byId[$step['id']] = ['index' => $index, 'step' => $step];
    }
    $required = [
        'preview controlled identity import',
        'confirm controlled identity import invitation',
        'audit controlled identity import preview',
        'audit controlled import invitation creation',
        'create controlled SCIM identity provider',
        'configure controlled SCIM identity provider',
        'issue controlled SCIM token',
        'SCIM creates controlled user',
        'SCIM reads controlled user',
        'SCIM updates controlled user',
        'SCIM disables controlled user',
        'SCIM deletes controlled user',
        'revoke controlled SCIM token',
        'revoked SCIM token is denied',
        'audit controlled SCIM lifecycle',
        'configure controlled Keycloak directory',
        'create controlled directory connector',
        'configure controlled directory connector',
        'test controlled directory connector',
        'run controlled directory create sync',
        'mutate controlled Keycloak directory',
        'run controlled directory update sync',
        'controlled directory proves both generations',
        'disable controlled directory connector',
        'create identity',
        'grant role to group',
        'authorization decision allows derived group role',
        'authorization decision denies outside policy',
    ];
    foreach ($required as $id) if (!isset($byId[$id])) throw new RuntimeException("Chain2 缺少固定目录或授权步骤：{$id}。");
    $index = static fn (string $id): int => $byId[$id]['index'];
    $step = static fn (string $id): array => $byId[$id]['step'];
    if (!($index('preview controlled identity import') < $index('confirm controlled identity import invitation')
        && $index('confirm controlled identity import invitation') < $index('create controlled SCIM identity provider')
        && $index('create controlled SCIM identity provider') < $index('configure controlled SCIM identity provider')
        && $index('configure controlled SCIM identity provider') < $index('issue controlled SCIM token')
        && $index('issue controlled SCIM token') < $index('SCIM creates controlled user')
        && $index('SCIM creates controlled user') < $index('SCIM reads controlled user')
        && $index('SCIM reads controlled user') < $index('SCIM updates controlled user')
        && $index('SCIM updates controlled user') < $index('SCIM disables controlled user')
        && $index('SCIM disables controlled user') < $index('SCIM deletes controlled user')
        && $index('SCIM deletes controlled user') < $index('revoke controlled SCIM token')
        && $index('revoke controlled SCIM token') < $index('revoked SCIM token is denied')
        && $index('revoked SCIM token is denied') < $index('audit controlled SCIM lifecycle')
        && $index('audit controlled SCIM lifecycle') < $index('configure controlled Keycloak directory')
        && $index('configure controlled Keycloak directory') < $index('create controlled directory connector')
        && $index('create controlled directory connector') < $index('configure controlled directory connector')
        && $index('configure controlled directory connector') < $index('test controlled directory connector')
        && $index('test controlled directory connector') < $index('run controlled directory create sync')
        && $index('run controlled directory create sync') < $index('mutate controlled Keycloak directory')
        && $index('mutate controlled Keycloak directory') < $index('run controlled directory update sync')
        && $index('run controlled directory update sync') < $index('controlled directory proves both generations')
        && $index('controlled directory proves both generations') < $index('disable controlled directory connector'))) {
        throw new RuntimeException('Chain2 必须按目录配置、连接测试、首次同步、目录变更、再次同步、证据和停用顺序执行。');
    }
    $directoryTarget = $targets['directory'] ?? null;
    $configuredDirectory = $step('configure controlled directory connector')['body']['config'] ?? null;
    if (!is_array($directoryTarget)
        || ($directoryTarget['kind'] ?? null) !== 'external'
        || !is_string($directoryTarget['base_url'] ?? null)
        || (!str_starts_with((string) $directoryTarget['base_url'], 'https://')
            && $directoryTarget['base_url'] !== '__REQUIRED_C02_DIRECTORY_URL__')
        || !is_array($configuredDirectory)
        || ($configuredDirectory['base_url'] ?? null) !== $directoryTarget['base_url']
        || ($directoryTarget['allow_paths'] ?? null) !== ['/admin/realms', '/directory']) {
        throw new RuntimeException('Chain2 必须使用独立 allowlist 的公网 HTTPS 目录 target。');
    }
    $create = $step('create controlled directory connector');
    $importPreview = $step('preview controlled identity import');
    $importConfirm = $step('confirm controlled identity import invitation');
    $scimProvider = $step('create controlled SCIM identity provider');
    $scimConfigure = $step('configure controlled SCIM identity provider');
    $scimToken = $step('issue controlled SCIM token');
    $scimCreate = $step('SCIM creates controlled user');
    $scimRead = $step('SCIM reads controlled user');
    $scimUpdate = $step('SCIM updates controlled user');
    $scimDisable = $step('SCIM disables controlled user');
    $scimDelete = $step('SCIM deletes controlled user');
    $scimRevoke = $step('revoke controlled SCIM token');
    $scimDenied = $step('revoked SCIM token is denied');
    $configure = $step('configure controlled directory connector');
    $firstRun = $step('run controlled directory create sync');
    $secondRun = $step('run controlled directory update sync');
    $proof = $step('controlled directory proves both generations');
    $importContent = (string) ($importPreview['multipart']['file']['content'] ?? '');
    $hasConcreteImportEmail = preg_match('/^[^,\r\n]*,[^,\r\n]*,[^,\r\n@]+@[^,\r\n@]+,[^,\r\n]*,[^,\r\n]*,[^,\r\n]*$/m', $importContent) === 1;
    if (($importPreview['path'] ?? null) !== '/app/sand-iam/admin/identity-import/preview'
        || (($importPreview['multipart']['fields']['mode'] ?? null) !== 'create')
        || (($importPreview['multipart']['file']['content_type'] ?? null) !== 'text/csv')
        || (!str_contains($importContent, '__REQUIRED_C02_IMPORT_EMAIL__') && !$hasConcreteImportEmail)
        || (($importPreview['capture']['identity_import_job_id']['path'] ?? null) !== 'data.id')
        || (($importPreview['capture']['identity_import_digest']['sensitive'] ?? null) !== true)
        || ($importConfirm['path'] ?? null) !== '/app/sand-iam/admin/identity-import/confirm'
        || (($importConfirm['body']['id'] ?? null) !== '${identity_import_job_id}')
        || (($importConfirm['body']['digest'] ?? null) !== '${identity_import_digest}')
        || (($importConfirm['assert']['json']['data.success'] ?? null) !== 1)
        || (($importConfirm['assert']['json']['data.warning'] ?? null) !== 0)
        || (($importConfirm['assert']['json']['data.failure'] ?? null) !== 0)
        || ($scimProvider['path'] ?? null) !== '/app/sand-iam/admin/identity-provider/save'
        || ($scimProvider['body']['scope_type'] ?? null) !== 'application'
        || ($scimProvider['capture']['identity_provider_id']['path'] ?? null) !== 'data.id'
        || ($scimProvider['capture']['scim_provider_code']['path'] ?? null) !== 'data.public_code'
        || ($scimConfigure['path'] ?? null) !== '/app/sand-iam/admin/federation/configure'
        || ($scimConfigure['body']['provider_type'] ?? null) !== 'scim'
        || ($scimConfigure['body']['provider_id'] ?? null) !== '${identity_provider_id}'
        || ($scimToken['path'] ?? null) !== '/app/sand-iam/admin/scim/token/issue'
        || ($scimToken['capture']['scim_access_token']['path'] ?? null) !== 'data.token'
        || ($scimToken['capture']['scim_access_token']['sensitive'] ?? null) !== true
        || ($scimCreate['auth'] ?? null) !== 'scim_token'
        || ($scimCreate['auth_capture'] ?? null) !== 'scim_access_token'
        || ($scimCreate['method'] ?? null) !== 'POST'
        || ($scimCreate['media_type'] ?? null) !== 'application/scim+json'
        || ($scimCreate['path'] ?? null) !== '/api/sand-iam/v1/scim/${scim_provider_code}/Users'
        || ($scimCreate['capture']['scim_user_ref']['path'] ?? null) !== 'id'
        || ($scimRead['media_type'] ?? null) !== 'application/scim+json'
        || ($scimUpdate['method'] ?? null) !== 'PATCH'
        || ($scimUpdate['media_type'] ?? null) !== 'application/scim+json'
        || ($scimUpdate['headers']['If-Match'] ?? null) !== '${scim_user_version}'
        || ($scimDisable['method'] ?? null) !== 'PATCH'
        || ($scimDisable['media_type'] ?? null) !== 'application/scim+json'
        || ($scimDisable['body']['Operations'][0]['path'] ?? null) !== 'active'
        || ($scimDisable['body']['Operations'][0]['value'] ?? null) !== false
        || ($scimDelete['method'] ?? null) !== 'DELETE'
        || ($scimDelete['media_type'] ?? null) !== 'application/scim+json'
        || ($scimDelete['headers']['If-Match'] ?? null) !== '${scim_disabled_version}'
        || ($scimRevoke['path'] ?? null) !== '/app/sand-iam/admin/scim/token/revoke'
        || ($scimRevoke['body']['token_id'] ?? null) !== '${scim_token_ref}'
        || ($scimDenied['proof'] ?? null) !== 'revoke_effective'
        || ($scimDenied['expect_http'] ?? null) !== 401
        || ($scimDenied['media_type'] ?? null) !== 'application/scim+json'
        || ($scimDenied['auth_capture'] ?? null) !== 'scim_access_token'
        || ($create['path'] ?? null) !== '/app/sand-iam/admin/sync-connector/save'
        || ($create['body']['driver_code'] ?? null) !== 'keycloak'
        || ($create['body']['direction'] ?? null) !== 'inbound'
        || ($create['capture']['sync_connector_id']['path'] ?? null) !== 'data.id'
        || ($configure['path'] ?? null) !== '/app/sand-iam/admin/sync-connector/configure'
        || ($configure['body']['id'] ?? null) !== '${sync_connector_id}'
        || !is_string($configure['body']['config']['realm'] ?? null)
        || trim((string) $configure['body']['config']['realm']) === ''
        || !is_string($configure['body']['config']['access_token'] ?? null)
        || trim((string) $configure['body']['config']['access_token']) === ''
        || ($firstRun['assert']['json']['data.created'] ?? null) !== 1
        || ($secondRun['assert']['json']['data.updated'] ?? null) !== 1
        || ($proof['assert']['json']['generations_seen'] ?? null) !== [1, 2]) {
        throw new RuntimeException('Chain2 目录连接、两代真实同步或外部证据契约被弱化。');
    }
    $cleanupIds = array_column($chain['cleanup']['steps'] ?? [], 'id');
    foreach ([
        'cleanup controlled Keycloak directory',
        'zero residual controlled Keycloak directory',
        'controlled cleanup directory sync only',
        'zero residual directory sync only',
        'controlled cleanup SCIM provider only',
        'zero residual SCIM provider only',
        'controlled cleanup identity import only',
        'zero residual identity import only',
    ] as $cleanupId) {
        if (!in_array($cleanupId, $cleanupIds, true)) throw new RuntimeException("Chain2 缺少目录夹具恢复步骤：{$cleanupId}。");
    }
}

function liveValidateHumanAuthSessionMfaProtocol(array $chain): void
{
    $byId = [];
    foreach ($chain['steps'] as $step) if (is_array($step) && is_string($step['id'] ?? null)) $byId[$step['id']] = $step;
    $expected = [
        'registration revoked session denied' => 'registration_session_token',
        'new revoked session denied' => 'login_session_token',
        'MFA verified revoked session denied' => 'mfa_login_session_token',
    ];
    $protectedEndpoint = null;
    foreach ($expected as $id => $tokenSource) {
        $step = $byId[$id] ?? null;
        if (!is_array($step)
            || ($step['proof'] ?? null) !== 'revoke_effective'
            || ($step['target'] ?? null) !== 'sandiam'
            || ($step['auth'] ?? null) !== 'application_user'
            || ($step['auth_capture'] ?? null) !== $tokenSource
            || ($step['method'] ?? null) !== 'GET'
            || ($step['write'] ?? null) !== false
            || ($step['expect_http'] ?? null) !== 401
            || !is_string($step['path'] ?? null)
            || !is_array($step['assert'] ?? null)) {
            throw new RuntimeException('Chain3 受保护端点撤销证据必须固定绑定 registration/login/MFA 三条会话与各自 token source。');
        }
        $protectedEndpoint ??= $step['path'];
        if ($step['path'] !== $protectedEndpoint) throw new RuntimeException('Chain3 三条撤销会话必须验证同一受保护端点。');
    }
    $revokeEffective = array_values(array_filter($chain['steps'], static fn (mixed $step): bool => is_array($step) && ($step['proof'] ?? null) === 'revoke_effective'));
    if (count($revokeEffective) !== 3 || $protectedEndpoint !== '/api/sand-iam/v1/auth/sessions') {
        throw new RuntimeException('Chain3 必须恰好保留三条固定 revoke_effective 证明，缺失任一会话即拒绝 live plan。');
    }
}

/** @param array<string,mixed> $chain @param array<string,array<string,mixed>> $targets */
function liveValidateOAuthCasApiGovernanceProtocol(array $chain, array $targets): void
{
    $steps = $chain['steps'] ?? [];
    if (!is_array($steps)) throw new RuntimeException('Chain5 缺少步骤集合。');
    $byId = [];
    foreach ($steps as $index => $step) {
        if (is_array($step) && is_string($step['id'] ?? null)) $byId[$step['id']] = ['index' => $index, 'step' => $step];
    }
    $required = [
        'create controlled API catalog entry', 'preview controlled route manifest',
        'apply controlled route manifest',
        'create OAuth client with distinct public code and database ID', 'OAuth authorize 302',
        'correct PKCE token', 'OAuth authorize distinct wrong-PKCE request',
        'wrong PKCE invalid grant on independent code', 'create controlled CAS service',
        'CAS client captures ticket', 'CAS XML validate', 'disable controlled CAS service',
        'CAS XML denies disabled service ticket', 'OAuth userinfo allows this-run access token',
        'revoke this-run OAuth grant', 'OAuth userinfo denies revoked access token',
        'create and bind controlled identity policy', 'publish controlled identity policy',
        'simulate controlled policy allow', 'provider route allows',
        'application session permits controlled API invocation',
        'revoke controlled identity policy', 'simulate controlled policy deny after revoke',
        'application session denies revoked policy API invocation', 'disable route', 'provider route deny',
    ];
    foreach ($required as $id) if (!isset($byId[$id])) throw new RuntimeException("Chain5 缺少固定协议步骤：{$id}。");
    $step = static fn (string $id): array => $byId[$id]['step'];
    $index = static fn (string $id): int => $byId[$id]['index'];
    if (!($index('create controlled API catalog entry') < $index('preview controlled route manifest')
        && $index('preview controlled route manifest') < $index('apply controlled route manifest')
        && $index('apply controlled route manifest') < $index('create and bind controlled identity policy')
        && $index('create and bind controlled identity policy') < $index('publish controlled identity policy')
        && $index('publish controlled identity policy') < $index('simulate controlled policy allow'))) {
        throw new RuntimeException('Chain5 必须按 catalog→route→policy publish→simulate 顺序验证接口治理。');
    }
    $routePreview = $step('preview controlled route manifest');
    $routeApply = $step('apply controlled route manifest');
    $catalog = $step('create controlled API catalog entry');
    $policyCreate = $step('create and bind controlled identity policy');
    $templateScope = ($catalog['body']['application_id'] ?? null) === '__REQUIRED_APPLICATION_ID__';
    $applicationId = filter_var($catalog['body']['application_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $resourceId = filter_var($catalog['body']['resource_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $identityId = filter_var($policyCreate['body']['identity_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (($routePreview['path'] ?? null) !== '/app/sand-iam/admin/developer/route-manifest/preview'
        || ($routePreview['write'] ?? null) !== false
        || (($routePreview['body']['manifest']['routes'][0]['sand_iam']['api_code'] ?? null) !== '${prefix}chain5-api')
        || (($routePreview['capture']['route_preview_hash']['path'] ?? null) !== 'data.preview_hash')
        || ($routeApply['path'] ?? null) !== '/app/sand-iam/admin/developer/route-manifest/apply'
        || (($routeApply['body']['preview_hash'] ?? null) !== '${route_preview_hash}')
        || (($routeApply['body']['apply'] ?? null) !== true)
        || (($routeApply['capture']['route_binding_id']['path'] ?? null) !== 'data.changes.0.binding_id')
        || (($routeApply['capture']['route_binding_audit_request']['path'] ?? null) !== 'data.changes.0.audit_request_id')
        || (($catalog['capture']['api_resource_id']['path'] ?? null) !== 'data.id')
        || ($templateScope
            ? (($policyCreate['body']['application_id'] ?? null) !== '__REQUIRED_APPLICATION_ID__'
                || ($policyCreate['body']['resource_id'] ?? null) !== '__REQUIRED_RESOURCE_ID__'
                || ($policyCreate['body']['identity_id'] ?? null) !== '__REQUIRED_APPLICATION_IDENTITY_ID__')
            : ($applicationId === false
                || $resourceId === false
                || $identityId === false
                || ($policyCreate['body']['application_id'] ?? null) !== ($catalog['body']['application_id'] ?? null)
                || ($policyCreate['body']['resource_id'] ?? null) !== ($catalog['body']['resource_id'] ?? null)))) {
        throw new RuntimeException('Chain5 catalog、route manifest 与 policy 必须绑定同一应用、本轮 API 代码、资源和身份。');
    }
    $pkcePositive = $step('correct PKCE token');
    $pkceNegative = $step('wrong PKCE invalid grant on independent code');
    $authorizePositive = $step('OAuth authorize 302');
    $authorizeNegative = $step('OAuth authorize distinct wrong-PKCE request');
    parse_str((string) parse_url((string) ($authorizePositive['path'] ?? ''), PHP_URL_QUERY), $authorizePositiveQuery);
    parse_str((string) parse_url((string) ($authorizeNegative['path'] ?? ''), PHP_URL_QUERY), $authorizeNegativeQuery);
    $pkceVerifier = (string) ($pkcePositive['body']['code_verifier'] ?? '');
    $wrongPkceVerifier = (string) ($pkceNegative['body']['code_verifier'] ?? '');
    $pkceChallenge = rtrim(strtr(base64_encode(hash('sha256', $pkceVerifier, true)), '+/', '-_'), '=');
    $templatePkce = $pkceVerifier === '__REQUIRED_PKCE_VERIFIER__';
    if (($authorizePositive['path'] ?? null) === ($authorizeNegative['path'] ?? null)
        || ($templatePkce
            ? (($authorizePositiveQuery['code_challenge'] ?? null) !== '__REQUIRED_PKCE_CHALLENGE__'
                || ($authorizeNegativeQuery['code_challenge'] ?? null) !== '__REQUIRED_PKCE_CHALLENGE__'
                || $wrongPkceVerifier !== '__REQUIRED_WRONG_PKCE_VERIFIER__')
            : (!is_string($authorizePositiveQuery['code_challenge'] ?? null)
                || ($authorizePositiveQuery['code_challenge'] ?? null) !== ($authorizeNegativeQuery['code_challenge'] ?? null)
                || ($authorizePositiveQuery['code_challenge'] ?? null) !== $pkceChallenge
                || !is_string($authorizePositiveQuery['redirect_uri'] ?? null)
                || ($authorizePositiveQuery['redirect_uri'] ?? null) !== ($authorizeNegativeQuery['redirect_uri'] ?? null)
                || ($authorizePositiveQuery['redirect_uri'] ?? null) !== ($pkcePositive['body']['redirect_uri'] ?? null)
                || !in_array($authorizePositiveQuery['redirect_uri'] ?? null, $step('create OAuth client with distinct public code and database ID')['body']['redirect_uris'] ?? [], true)))
        || (($authorizePositive['capture']['oauth_request']['sensitive'] ?? null) !== true)
        || (($authorizeNegative['capture']['oauth_request_wrong']['sensitive'] ?? null) !== true)
        || ($pkcePositive['proof'] ?? null) !== 'allow' || ($pkcePositive['target'] ?? null) !== 'sandiam'
        || ($pkcePositive['auth'] ?? null) !== 'service_client' || ($pkcePositive['path'] ?? null) !== '/api/sand-iam/v1/oauth/token'
        || ($pkcePositive['expect_http'] ?? null) !== 200
        || (!$templatePkce && preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $pkceVerifier) !== 1)
        || (($pkcePositive['body']['code'] ?? null) !== '${oauth_code}') || (($pkcePositive['capture']['oauth_access_token']['path'] ?? null) !== 'access_token')
        || ($pkceNegative['proof'] ?? null) !== 'deny' || ($pkceNegative['target'] ?? null) !== 'sandiam'
        || ($pkceNegative['auth'] ?? null) !== 'service_client' || ($pkceNegative['path'] ?? null) !== '/api/sand-iam/v1/oauth/token'
        || ($pkceNegative['expect_http'] ?? null) !== 400
        || (!$templatePkce && preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $wrongPkceVerifier) !== 1)
        || (!$templatePkce && hash_equals($pkceVerifier, $wrongPkceVerifier))
        || (($pkceNegative['body']['code'] ?? null) !== '${oauth_code_wrong}')) {
        throw new RuntimeException('Chain5 OAuth PKCE 正反例必须使用独立授权码并固定 token 端点结果。');
    }
    $casCapture = $step('CAS client captures ticket');
    $casValidate = $step('CAS XML validate');
    $casDisable = $step('disable controlled CAS service');
    $casDenied = $step('CAS XML denies disabled service ticket');
    if (($casCapture['target'] ?? null) !== 'cas_client' || ($casCapture['auth'] ?? null) !== 'none'
        || ($casCapture['path'] ?? null) !== '/cas/callback' || ($casCapture['capture']['cas_ticket']['sensitive'] ?? null) !== true
        || ($casValidate['target'] ?? null) !== 'sandiam' || ($casValidate['auth'] ?? null) !== 'service_client'
        || ($casValidate['method'] ?? null) !== 'GET' || !str_starts_with((string) ($casValidate['path'] ?? ''), '/api/sand-iam/v1/cas/serviceValidate?')
        || !str_contains((string) ($casValidate['path'] ?? ''), 'ticket=${cas_ticket}')
        || (($casValidate['assert']['headers']['content-type']['contains'] ?? null) !== 'application/xml')
        || (($casValidate['assert']['body']['contains'] ?? null) !== '<cas:authenticationSuccess>')
        || ($casDisable['path'] ?? null) !== '/app/sand-iam/admin/cas-service/disable'
        || (($casDisable['body']['id'] ?? null) !== '${cas_service_id}')
        || ($casDenied['target'] ?? null) !== 'sandiam' || ($casDenied['auth'] ?? null) !== 'service_client'
        || !str_starts_with((string) ($casDenied['path'] ?? ''), '/api/sand-iam/v1/cas/serviceValidate?')
        || ($casDenied['expect_http'] ?? null) !== 200
        || (($casDenied['assert']['headers']['content-type']['contains'] ?? null) !== 'application/xml')
        || (($casDenied['assert']['body']['contains'] ?? null) !== '<cas:authenticationFailure')) {
        throw new RuntimeException('Chain5 CAS 必须由 ticket 回调、XML serviceValidate 和停用后的 XML 拒绝构成，userinfo 或无关 API 不能代替。');
    }
    $oauthAllow = $step('OAuth userinfo allows this-run access token');
    $oauthDeny = $step('OAuth userinfo denies revoked access token');
    if (($oauthAllow['auth_capture'] ?? null) !== 'oauth_access_token' || ($oauthAllow['path'] ?? null) !== '/api/sand-iam/v1/oauth/userinfo'
        || ($oauthDeny['proof'] ?? null) !== 'revoke_effective' || ($oauthDeny['auth_capture'] ?? null) !== 'oauth_access_token'
        || ($oauthDeny['path'] ?? null) !== '/api/sand-iam/v1/oauth/userinfo' || ($oauthDeny['expect_http'] ?? null) !== 401) {
        throw new RuntimeException('Chain5 OAuth 撤销必须以本轮 access token 在 userinfo 返回 401 证明生效。');
    }
    $oauthRevoke = $step('revoke this-run OAuth grant');
    if (($oauthRevoke['path'] ?? null) !== '/api/sand-iam/v1/oauth/revoke'
        || (($oauthRevoke['body']['client_id'] ?? null) !== '${oauth_client_code}')
        || (($oauthRevoke['body']['token'] ?? null) !== '${oauth_access_token}')) {
        throw new RuntimeException('Chain5 OAuth 撤销必须绑定本轮 client 和 access token。');
    }
    foreach ([
        'simulate controlled policy allow' => ['proof' => 'allow', 'auth' => 'platform_admin', 'path' => '/app/sand-iam/admin/policy/simulate', 'allowed' => true],
        'simulate controlled policy deny after revoke' => ['proof' => 'deny', 'auth' => 'platform_admin', 'path' => '/app/sand-iam/admin/policy/simulate', 'allowed' => false],
        'application session permits controlled API invocation' => ['proof' => 'allow', 'auth' => 'application_user', 'path' => '/api/sand-iam/v1/authorization/decide', 'allowed' => true],
        'application session denies revoked policy API invocation' => ['proof' => 'revoke_effective', 'auth' => 'application_user', 'path' => '/api/sand-iam/v1/authorization/decide', 'allowed' => false],
    ] as $id => $expected) {
        $candidate = $step($id);
        if (($candidate['proof'] ?? null) !== $expected['proof'] || ($candidate['target'] ?? null) !== 'sandiam'
            || ($candidate['auth'] ?? null) !== $expected['auth'] || ($candidate['path'] ?? null) !== $expected['path']
            || (($candidate['assert']['json']['data.allowed'] ?? null) !== $expected['allowed'])) {
            throw new RuntimeException('Chain5 策略模拟与应用调用必须各自提供固定 allow/deny 证据。');
        }
    }
    $policyRevoke = $step('revoke controlled identity policy');
    if (($policyRevoke['proof'] ?? null) !== 'revoke_or_recover' || ($policyRevoke['target'] ?? null) !== 'sandiam'
        || ($policyRevoke['auth'] ?? null) !== 'platform_admin' || ($policyRevoke['path'] ?? null) !== '/app/sand-iam/admin/policy/revoke'
        || (($policyRevoke['body']['id'] ?? null) !== '${policy_id}')) {
        throw new RuntimeException('Chain5 策略撤销必须精确绑定本轮 policy_id，不能以路由或无关动作代替。');
    }
    $simulateAllow = $step('simulate controlled policy allow');
    $simulateDeny = $step('simulate controlled policy deny after revoke');
    $appAllow = $step('application session permits controlled API invocation');
    $appDeny = $step('application session denies revoked policy API invocation');
    $catalogCode = $catalog['body']['code'] ?? null;
    foreach ([$simulateAllow, $simulateDeny] as $simulation) {
        if (($templateScope
                ? (($simulation['body']['application_id'] ?? null) !== '__REQUIRED_APPLICATION_ID__'
                    || ($simulation['body']['identity_id'] ?? null) !== '__REQUIRED_APPLICATION_IDENTITY_ID__'
                    || ($simulation['body']['resource_code'] ?? null) !== '__REQUIRED_RESOURCE_CODE__')
                : (($simulation['body']['application_id'] ?? null) !== ($catalog['body']['application_id'] ?? null)
                    || ($simulation['body']['identity_id'] ?? null) !== ($policyCreate['body']['identity_id'] ?? null)
                    || !is_string($simulation['body']['resource_code'] ?? null)
                    || trim((string) ($simulation['body']['resource_code'] ?? '')) === ''
                    || ($simulation['body']['resource_code'] ?? null) !== ($simulateAllow['body']['resource_code'] ?? null)))
            || ($simulation['body']['action'] ?? null) !== ($catalog['body']['action'] ?? null)
            || ($simulation['body']['operation'] ?? null) !== ($catalog['body']['operation'] ?? null)) {
            throw new RuntimeException('Chain5 策略 simulate 必须绑定本轮业务资源、身份、动作和 catalog 操作。');
        }
    }
    foreach ([$appAllow, $appDeny] as $applicationCall) {
        if (($applicationCall['body']['api_code'] ?? null) !== $catalogCode
            || ($applicationCall['body']['api_version'] ?? null) !== ($catalog['body']['api_version'] ?? null)
            || (($applicationCall['assert']['json']['data.api_code'] ?? $catalogCode) !== $catalogCode)) {
            throw new RuntimeException('Chain5 应用 allow/deny 必须调用本轮 catalog API 并返回同一 api_code。');
        }
    }
    $providerAllow = $step('provider route allows');
    $providerDeny = $step('provider route deny');
    $routeDisable = $step('disable route');
    $routeTemplate = (string) ($routeApply['body']['manifest']['routes'][0]['path'] ?? '');
    $providerPath = (string) ($providerAllow['path'] ?? '');
    $routePattern = '~^' . preg_replace('/\\\\\\{[A-Za-z][A-Za-z0-9_]*\\\\\\}/', '[^/?#]+', preg_quote($routeTemplate, '~')) . '$~D';
    $providerPathMatches = (
        $routeTemplate === '__REQUIRED_PROVIDER_ROUTE_TEMPLATE__'
        && $providerPath === '__REQUIRED_PROVIDER_ROUTE_PATH__'
    ) || @preg_match($routePattern, $providerPath) === 1;
    if (($targets['business_app']['kind'] ?? null) !== 'business_app'
        || ($providerAllow['target'] ?? null) !== 'business_app'
        || ($providerAllow['auth'] ?? null) !== 'application_user'
        || ($providerAllow['method'] ?? null) !== 'POST'
        || ($providerAllow['write'] ?? null) !== true
        || ($providerAllow['request_id'] ?? null) !== '${prefix}chain5-provider-allow'
        || ($providerAllow['body'] ?? null) !== []
        || $routeTemplate === ''
        || $providerPath === ''
        || !$providerPathMatches
        || (($providerAllow['assert']['json']['allowed'] ?? null) !== true)
        || (($providerAllow['assert']['json']['authorization.api_code'] ?? null) !== '${prefix}chain5-api')
        || (($providerAllow['assert']['json']['authorization.request_id'] ?? null) !== '${prefix}chain5-provider-allow')
        || (($providerAllow['capture']['business_audit_allow_id']['path'] ?? null) !== 'business_audit_id')
        || (($providerAllow['capture']['business_item_id']['path'] ?? null) !== 'item.id')
        || ($routeDisable['path'] ?? null) !== '/app/sand-iam/admin/api-route-binding/disable'
        || (($routeDisable['body']['id'] ?? null) !== '${route_binding_id}')
        || ($providerDeny['target'] ?? null) !== 'business_app'
        || ($providerDeny['auth'] ?? null) !== 'application_user'
        || ($providerDeny['method'] ?? null) !== 'POST'
        || ($providerDeny['write'] ?? null) !== true
        || ($providerDeny['request_id'] ?? null) !== '${prefix}chain5-provider-route-disabled'
        || ($providerDeny['path'] ?? null) !== $providerPath
        || ($providerDeny['body'] ?? null) !== []
        || (($providerDeny['assert']['json']['allowed'] ?? null) !== false)
        || (($providerDeny['assert']['json']['error'] ?? null) !== 'SAND_IAM_ROUTE_NOT_REGISTERED')
        || (($providerDeny['capture']['business_audit_deny_id']['path'] ?? null) !== 'business_audit_id')) {
        throw new RuntimeException('Chain5 真实业务路由必须使用 application_user 经过已登记 Webman 路由，回传同一 api_code/request_id，并在绑定停用后以固定错误拒绝。');
    }
    $cleanup = $chain['cleanup']['steps'] ?? [];
    $cleanupIds = array_column(is_array($cleanup) ? $cleanup : [], 'id');
    if ($cleanupIds !== [
        'controlled cleanup complete C05 business audit',
        'zero residual complete C05 business audit',
        'controlled cleanup partial C05 business audit',
        'zero residual partial C05 business audit',
        'controlled cleanup OAuth CAS API governance fixture',
        'zero residual OAuth CAS API governance fixture',
    ]) {
        throw new RuntimeException('Chain5 必须分别清理真实业务审计和 SandIAM 夹具，并以各自零残留查询收口。');
    }
    $cleanupAction = $cleanup[4] ?? [];
    $cleanupStatus = $cleanup[5] ?? [];
    $statusPath = (string) ($cleanupStatus['path'] ?? '');
    parse_str((string) parse_url($statusPath, PHP_URL_QUERY), $statusQuery);
    $environmentId = filter_var($cleanupAction['body']['environment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($templateScope
        ? (($cleanupAction['body']['application_id'] ?? null) !== '__REQUIRED_APPLICATION_ID__'
            || ($cleanupAction['body']['environment_id'] ?? null) !== '__REQUIRED_ENVIRONMENT_ID__'
            || ($cleanupAction['body']['resource_id'] ?? null) !== '__REQUIRED_RESOURCE_ID__'
            || ($cleanupAction['body']['identity_id'] ?? null) !== '__REQUIRED_APPLICATION_IDENTITY_ID__'
            || ($statusQuery['application_id'] ?? null) !== '__REQUIRED_APPLICATION_ID__'
            || ($statusQuery['environment_id'] ?? null) !== '__REQUIRED_ENVIRONMENT_ID__'
            || ($statusQuery['resource_id'] ?? null) !== '__REQUIRED_RESOURCE_ID__'
            || ($statusQuery['identity_id'] ?? null) !== '__REQUIRED_APPLICATION_IDENTITY_ID__')
        : (($cleanupAction['body']['application_id'] ?? null) !== ($catalog['body']['application_id'] ?? null)
            || ($cleanupAction['body']['resource_id'] ?? null) !== ($catalog['body']['resource_id'] ?? null)
            || ($cleanupAction['body']['identity_id'] ?? null) !== ($policyCreate['body']['identity_id'] ?? null)
            || $environmentId === false
            || (string) ($statusQuery['application_id'] ?? '') !== (string) ($cleanupAction['body']['application_id'] ?? '')
            || (string) ($statusQuery['environment_id'] ?? '') !== (string) ($cleanupAction['body']['environment_id'] ?? '')
            || (string) ($statusQuery['resource_id'] ?? '') !== (string) ($cleanupAction['body']['resource_id'] ?? '')
            || (string) ($statusQuery['identity_id'] ?? '') !== (string) ($cleanupAction['body']['identity_id'] ?? ''))) {
        throw new RuntimeException('Chain5 cleanup/status 必须携带同一轮 application、environment、resource 和 identity 前置范围。');
    }
    $residualTypes = ['oauth_client', 'cas_service', 'api_resource', 'api_route_binding', 'policy', 'oauth_authorization_request', 'authorization_code', 'oauth_consent', 'oauth_grant', 'oauth_token', 'cas_login_request', 'cas_ticket', 'policy_version'];
    foreach ([$cleanupAction, $cleanupStatus] as $cleanupStep) {
        $assertions = $cleanupStep['assert']['json'] ?? [];
        if (!is_array($assertions)) throw new RuntimeException('Chain5 cleanup/status 必须声明 13 类零残留断言。');
        foreach ($residualTypes as $type) if (($assertions['data.residual.' . $type] ?? null) !== 0) throw new RuntimeException('Chain5 cleanup/status 缺少 13 类对象的零残留断言。');
    }
}

function liveValidateDelegationScopeProtocol(array $chain): void
{
    $byId = [];
    foreach ($chain['steps'] as $step) {
        if (is_array($step)) $byId[(string) ($step['id'] ?? '')] = $step;
    }
    foreach ([
        'create delegation',
        'scoped creates in scope environment',
        'scoped in scope allow',
        'same scoped out scope deny',
        'audit same scoped out scope deny',
        'independent out of scope admin deny',
        'audit independent out of scope admin deny',
        'audit scoped environment creation',
        'audit this-run delegation creation',
        'scoped disables in scope environment',
        'audit scoped environment disable',
        'disable delegation',
        'audit this-run delegation disable',
        'same scoped revoked deny',
        'audit same scoped revoked deny',
    ] as $id) {
        if (!isset($byId[$id])) throw new RuntimeException("Chain6 缺少固定三角色委派步骤：{$id}。");
    }

    $create = $byId['create delegation'];
    $environmentCreate = $byId['scoped creates in scope environment'];
    $allow = $byId['scoped in scope allow'];
    $scopeDeny = $byId['same scoped out scope deny'];
    $thirdRoleDeny = $byId['independent out of scope admin deny'];
    $environmentDisable = $byId['scoped disables in scope environment'];
    $disable = $byId['disable delegation'];
    $revokedDeny = $byId['same scoped revoked deny'];
    $queryValue = static function (string $path, string $key): string {
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        return is_scalar($query[$key] ?? null) ? (string) $query[$key] : '';
    };
    $scopedAdminId = (string) ($create['body']['admin_user_id'] ?? '');
    $inScopeApplicationId = (string) ($create['body']['application_id'] ?? '');
    $outOfScopeApplicationId = $queryValue((string) ($scopeDeny['path'] ?? ''), 'id');
    $outOfScopeAdminId = $queryValue((string) ($byId['audit independent out of scope admin deny']['path'] ?? ''), 'actor_ref');
    $scopeIdentifiers = [
        [$scopedAdminId, '__REQUIRED_SCOPED_ADMIN_ID__'],
        [$inScopeApplicationId, '__REQUIRED_IN_SCOPE_APPLICATION_ID__'],
        [$outOfScopeApplicationId, '__REQUIRED_OUT_OF_SCOPE_APPLICATION_ID__'],
        [$outOfScopeAdminId, '__REQUIRED_OUT_OF_SCOPE_ADMIN_ID__'],
    ];
    foreach ($scopeIdentifiers as [$scopeIdentifier, $placeholder]) {
        if (preg_match('/^[1-9][0-9]*$/', $scopeIdentifier) !== 1 && $scopeIdentifier !== $placeholder) {
            throw new RuntimeException('Chain6 必须使用本轮真实管理员和应用编号。');
        }
    }
    if ($inScopeApplicationId === $outOfScopeApplicationId || $scopedAdminId === $outOfScopeAdminId) {
        throw new RuntimeException('Chain6 范围内和范围外管理员、应用必须相互独立。');
    }
    if (($create['auth'] ?? null) !== 'platform_admin'
        || ($create['request_id'] ?? null) !== '${prefix}delegation-create'
        || ((string) ($create['body']['admin_user_id'] ?? '') !== $scopedAdminId)
        || ((string) ($create['body']['application_id'] ?? '') !== $inScopeApplicationId)
        || ($environmentCreate['auth'] ?? null) !== 'scoped_admin'
        || ($environmentCreate['request_id'] ?? null) !== '${prefix}delegation-env-create'
        || ($environmentCreate['path'] ?? null) !== '/app/sand-iam/admin/environment/save'
        || ((string) ($environmentCreate['body']['application_id'] ?? '') !== $inScopeApplicationId)
        || (($environmentCreate['capture']['delegated_environment_id']['path'] ?? null) !== 'data.id')
        || ($allow['auth'] ?? null) !== 'scoped_admin'
        || ($allow['request_id'] ?? null) !== '${prefix}delegation-in-scope-allow'
        || ($allow['path'] ?? null) !== '/app/sand-iam/admin/environment/read?id=${delegated_environment_id}'
        || (($allow['assert']['json']['data.id'] ?? null) !== '${delegated_environment_id}')
        || ($scopeDeny['auth'] ?? null) !== 'scoped_admin'
        || ($scopeDeny['request_id'] ?? null) !== '${prefix}delegation-scope-deny'
        || $queryValue((string) ($scopeDeny['path'] ?? ''), 'id') !== $outOfScopeApplicationId
        || (($scopeDeny['assert']['body']['contains'] ?? null) !== 'SAND_IAM_APPLICATION_ACCESS_DENIED')
        || ($thirdRoleDeny['auth'] ?? null) !== 'out_of_scope_admin'
        || ($thirdRoleDeny['request_id'] ?? null) !== '${prefix}delegation-third-role-deny'
        || $queryValue((string) ($thirdRoleDeny['path'] ?? ''), 'id') !== $inScopeApplicationId
        || (($thirdRoleDeny['assert']['body']['contains'] ?? null) !== 'SAND_IAM_APPLICATION_ACCESS_DENIED')
        || ($environmentDisable['auth'] ?? null) !== 'scoped_admin'
        || ($environmentDisable['request_id'] ?? null) !== '${prefix}delegation-env-disable'
        || ($environmentDisable['path'] ?? null) !== '/app/sand-iam/admin/environment/disable'
        || (($environmentDisable['body']['id'] ?? null) !== '${delegated_environment_id}')
        || ($disable['auth'] ?? null) !== 'platform_admin'
        || ($disable['request_id'] ?? null) !== '${prefix}delegation-disable'
        || (($disable['body']['id'] ?? null) !== '${delegation_id}')
        || ($revokedDeny['auth'] ?? null) !== 'scoped_admin'
        || ($revokedDeny['request_id'] ?? null) !== '${prefix}delegation-revoked-deny'
        || $queryValue((string) ($revokedDeny['path'] ?? ''), 'id') !== $inScopeApplicationId
        || (($revokedDeny['assert']['body']['contains'] ?? null) !== 'SAND_IAM_APPLICATION_ACCESS_DENIED')) {
        throw new RuntimeException('Chain6 必须由同一被委派管理员完成范围内允许、范围外拒绝和撤权后拒绝，并由独立范围外管理员证明第三角色边界。');
    }

    foreach ([
        'audit scoped environment creation' => ['action' => 'environment.create', 'request' => '${prefix}delegation-env-create'],
        'audit scoped environment disable' => ['action' => 'environment.disable', 'request' => '${prefix}delegation-env-disable'],
    ] as $id => $expected) {
        $audit = $byId[$id];
        $contains = $audit['assert']['json_contains']['data.data']['contains'] ?? [];
        if ($queryValue((string) ($audit['path'] ?? ''), 'actor_ref') !== $scopedAdminId
            || !str_contains((string) ($audit['path'] ?? ''), 'action=' . $expected['action'])
            || !str_contains((string) ($audit['path'] ?? ''), 'resource_id=${delegated_environment_id}')
            || !str_contains((string) ($audit['path'] ?? ''), 'request_id=' . $expected['request'])
            || (string) ($contains['actor_ref'] ?? '') !== $scopedAdminId
            || ($contains['action'] ?? null) !== $expected['action']
            || ($contains['resource_id'] ?? null) !== '${delegated_environment_id}'
            || ($contains['request_id'] ?? null) !== $expected['request']
            || ($contains['outcome'] ?? null) !== 'succeeded') {
            throw new RuntimeException("Chain6 范围内业务写入追溯不完整：{$id}。");
        }
    }

    foreach ([
        'audit same scoped out scope deny' => ['actor' => $scopedAdminId, 'resource' => $outOfScopeApplicationId, 'request' => '${prefix}delegation-scope-deny'],
        'audit independent out of scope admin deny' => ['actor' => $outOfScopeAdminId, 'resource' => $inScopeApplicationId, 'request' => '${prefix}delegation-third-role-deny'],
        'audit same scoped revoked deny' => ['actor' => $scopedAdminId, 'resource' => $inScopeApplicationId, 'request' => '${prefix}delegation-revoked-deny'],
    ] as $id => $expected) {
        $audit = $byId[$id];
        $contains = $audit['assert']['json_contains']['data.data']['contains'] ?? [];
        if (($audit['auth'] ?? null) !== 'platform_admin'
            || !str_contains((string) ($audit['path'] ?? ''), 'action=application.access')
            || $queryValue((string) ($audit['path'] ?? ''), 'actor_ref') !== $expected['actor']
            || $queryValue((string) ($audit['path'] ?? ''), 'resource_id') !== $expected['resource']
            || !str_contains((string) ($audit['path'] ?? ''), 'request_id=' . $expected['request'])
            || (string) ($contains['actor_ref'] ?? '') !== $expected['actor']
            || (string) ($contains['resource_id'] ?? '') !== $expected['resource']
            || ($contains['request_id'] ?? null) !== $expected['request']
            || ($contains['outcome'] ?? null) !== 'denied') {
            throw new RuntimeException("Chain6 拒绝追溯未绑定真实主体、应用和请求号：{$id}。");
        }
    }

    foreach ([
        'audit this-run delegation creation' => ['action' => 'admin_application_grant.create', 'request' => '${prefix}delegation-create'],
        'audit this-run delegation disable' => ['action' => 'admin_application_grant.disable', 'request' => '${prefix}delegation-disable'],
    ] as $id => $expected) {
        $audit = $byId[$id];
        $contains = $audit['assert']['json_contains']['data.data']['contains'] ?? [];
        if (!str_contains((string) ($audit['path'] ?? ''), 'action=' . $expected['action'])
            || !str_contains((string) ($audit['path'] ?? ''), 'resource_id=${delegation_id}')
            || !str_contains((string) ($audit['path'] ?? ''), 'request_id=' . $expected['request'])
            || ($contains['action'] ?? null) !== $expected['action']
            || ($contains['resource_id'] ?? null) !== '${delegation_id}'
            || ($contains['request_id'] ?? null) !== $expected['request']
            || ($contains['outcome'] ?? null) !== 'succeeded') {
            throw new RuntimeException("Chain6 委派生命周期追溯不完整：{$id}。");
        }
    }

    $cleanup = $chain['cleanup']['steps'] ?? [];
    $cleanupAction = is_array($cleanup) ? ($cleanup[0] ?? []) : [];
    $cleanupStatus = is_array($cleanup) ? ($cleanup[1] ?? []) : [];
    $partialCleanup = is_array($cleanup) ? ($cleanup[2] ?? []) : [];
    $partialStatus = is_array($cleanup) ? ($cleanup[3] ?? []) : [];
    if (array_column(is_array($cleanup) ? $cleanup : [], 'id') !== [
            'controlled cleanup delegation',
            'zero residual delegation',
            'controlled cleanup interrupted delegation',
            'zero residual interrupted delegation',
        ]
        || (string) ($cleanupAction['body']['application_id'] ?? '') !== $inScopeApplicationId
        || (($cleanupAction['body']['object_ids']['environment'][0] ?? null) !== '${delegated_environment_id}')
        || (($cleanupAction['body']['object_ids']['admin_application_grant'][0] ?? null) !== '${delegation_id}')
        || (($cleanupAction['body']['object_request_ids']['environment'][0] ?? null) !== '${prefix}delegation-env-create')
        || (($cleanupAction['body']['object_request_ids']['admin_application_grant'][0] ?? null) !== '${prefix}delegation-create')
        || (($cleanupAction['assert']['json']['data.residual.environment'] ?? null) !== 0)
        || (($cleanupAction['assert']['json']['data.residual.admin_application_grant'] ?? null) !== 0)
        || (($cleanupAction['run_if_capture_ids'] ?? null) !== ['delegation_id', 'delegated_environment_id'])
        || $queryValue((string) ($cleanupStatus['path'] ?? ''), 'application_id') !== $inScopeApplicationId
        || !str_contains((string) ($cleanupStatus['path'] ?? ''), 'object_ids%5Benvironment%5D%5B%5D=${delegated_environment_id}')
        || !str_contains((string) ($cleanupStatus['path'] ?? ''), 'object_ids%5Badmin_application_grant%5D%5B%5D=${delegation_id}')
        || (($cleanupStatus['run_if_capture_ids'] ?? null) !== ['delegation_id', 'delegated_environment_id'])
        || (($cleanupStatus['assert']['json']['data.residual.environment'] ?? null) !== 0)
        || (($cleanupStatus['assert']['json']['data.residual.admin_application_grant'] ?? null) !== 0)
        || (string) ($partialCleanup['body']['application_id'] ?? '') !== $inScopeApplicationId
        || (($partialCleanup['body']['object_ids'] ?? null) !== ['admin_application_grant' => ['${delegation_id}']])
        || (($partialCleanup['run_if_capture_ids'] ?? null) !== ['delegation_id'])
        || (($partialCleanup['run_unless_capture_ids'] ?? null) !== ['delegated_environment_id'])
        || (($partialCleanup['assert']['json']['data.residual.admin_application_grant'] ?? null) !== 0)
        || $queryValue((string) ($partialStatus['path'] ?? ''), 'application_id') !== $inScopeApplicationId
        || !str_contains((string) ($partialStatus['path'] ?? ''), 'object_ids%5Badmin_application_grant%5D%5B%5D=${delegation_id}')
        || (($partialStatus['run_if_capture_ids'] ?? null) !== ['delegation_id'])
        || (($partialStatus['run_unless_capture_ids'] ?? null) !== ['delegated_environment_id'])
        || (($partialStatus['assert']['json']['data.residual.admin_application_grant'] ?? null) !== 0)) {
        throw new RuntimeException('Chain6 必须分别受控清理完整委派写入与只创建委派的中断分支，并证明零残留。');
    }
}

function liveValidateWebhookDeliveryProtocol(array $chain, array $targets): void
{
    $byId = [];
    foreach ($chain['steps'] as $step) if (is_array($step)) $byId[(string) ($step['id'] ?? '')] = $step;
    foreach ([
        'create webhook',
        'configure controlled receiver',
        'issue credential business event',
        'worker 500 recorded',
        'retry queues delivery',
        'receiver proves retry success',
        'audit delivery',
        'disable webhook',
        'revoke event credential',
        'disabled is status two',
    ] as $id) {
        if (!isset($byId[$id])) throw new RuntimeException("Webhook live 链缺少固定协议步骤：{$id}。");
    }
    $create = $byId['create webhook'];
    $webhookUrl = (string) ($create['body']['url'] ?? '');
    if (($create['body']['event_types'] ?? null) !== ['credential.changed']
        || ($create['capture']['webhook_secret']['sensitive'] ?? null) !== true
        || !str_contains($webhookUrl, '/webhook/receive')
        || !str_contains($webhookUrl, 'scope=${prefix}')
        || (!str_contains($webhookUrl, '__REQUIRED_')
            && !str_starts_with($webhookUrl, rtrim((string) ($targets['webhook_receiver']['base_url'] ?? ''), '/') . '/webhook/receive?'))) {
        throw new RuntimeException('Webhook 必须订阅生产 credential.changed，并安全捕获一次性签名密钥。');
    }
    $configure = $byId['configure controlled receiver'];
    if (($configure['target'] ?? null) !== 'webhook_receiver'
        || ($configure['auth'] ?? null) !== 'none'
        || ($configure['write'] ?? null) !== true
        || ($configure['body']['secret'] ?? null) !== '${webhook_secret}'
        || ($configure['body']['endpoint_id'] ?? null) !== '${webhook_id}'
        || ($configure['capture']['receiver_config_id']['path'] ?? null) !== 'receiver_config_id') {
        throw new RuntimeException('受控接收器必须在事件发生前绑定本轮端点、密钥与临时配置编号。');
    }
    $trigger = $byId['issue credential business event'];
    if (($trigger['path'] ?? null) !== '/app/sand-iam/admin/credential/issue'
        || ($trigger['request_id'] ?? null) !== '${prefix}chain7-credential-issue'
        || ($trigger['capture']['credential_id']['path'] ?? null) !== 'data.id'
        || ($trigger['capture']['credential_plaintext']['sensitive'] ?? null) !== true) {
        throw new RuntimeException('Webhook 真实事件必须由本轮 credential.issue 业务变化触发。');
    }
    $failure = $byId['worker 500 recorded'];
    if (($failure['poll'] ?? null) !== ['max_attempts' => 12, 'interval_ms' => 1000]
        || !str_contains((string) ($failure['path'] ?? ''), 'webhook_endpoint_id=${webhook_id}')
        || (($failure['capture']['delivery_id']['path'] ?? null) !== 'data.data.0.id')
        || (($failure['assert']['json_contains']['data.data']['contains']['event_type'] ?? null) !== 'credential.changed')
        || (($failure['assert']['json_contains']['data.data']['contains']['response_status'] ?? null) !== 500)
        || (($failure['assert']['json_contains']['data.data']['contains']['status'] ?? null) !== 1)) {
        throw new RuntimeException('Webhook 首次 500 必须以绑定端点和真实事件类型的有界只读轮询证明并捕获 delivery。');
    }
    $retry = $byId['retry queues delivery'];
    if (($retry['fixture_capture_ids'] ?? null) !== ['webhook_id', 'delivery_id']
        || (($retry['body']['id'] ?? null) !== '${delivery_id}')
        || (($retry['request_id'] ?? null) !== '${prefix}chain7-delivery-retry')) {
        throw new RuntimeException('Webhook retry 必须固定绑定本轮 endpoint、delivery 与重试 request_id。');
    }
    $receiver = $byId['receiver proves retry success'];
    if (($receiver['target'] ?? null) !== 'webhook_receiver'
        || (($targets['webhook_receiver']['kind'] ?? null) !== 'external')
        || ($receiver['auth'] ?? null) !== 'none'
        || ($receiver['write'] ?? null) !== false
        || ($receiver['effect'] ?? null) !== 'non_persistent'
        || ($receiver['poll'] ?? null) !== ['max_attempts' => 12, 'interval_ms' => 1000]
        || !str_contains((string) ($receiver['path'] ?? ''), 'scope=${prefix}')
        || (($receiver['assert']['json']['receiver_config_id'] ?? null) !== '${receiver_config_id}')
        || (($receiver['assert']['json']['credential_id'] ?? null) !== '${credential_id}')
        || (($receiver['assert']['json']['credential_issue_request_id'] ?? null) !== '${prefix}chain7-credential-issue')
        || (($receiver['assert']['json']['signature_verified'] ?? null) !== true)
        || (($receiver['assert']['json']['attempt_count'] ?? null) !== 2)
        || (($receiver['assert']['json']['first_status'] ?? null) !== 500)
        || (($receiver['assert']['json']['last_status'] ?? null) !== 204)) {
        throw new RuntimeException('Webhook receiver 只能作非持久观测，且必须证明签名、真实凭证事件与 500→retry→204。');
    }
    $audit = $byId['audit delivery'];
    if (!str_contains((string) ($audit['path'] ?? ''), 'outcome=succeeded')
        || (($audit['assert']['json_contains']['data.data']['contains']['outcome'] ?? null) !== 'succeeded')
        || (($audit['assert']['json_contains']['data.data']['contains']['context']['contains']['delivery_state'] ?? null) !== 'delivered')) {
        throw new RuntimeException('Webhook 验收必须证明最终成功审计 outcome=succeeded 且 delivery_state=delivered；审计缺失不得通过。');
    }
    $disable = $byId['disable webhook'];
    if (($disable['fixture_capture_ids'] ?? null) !== ['webhook_id']) throw new RuntimeException('Webhook 停用必须明确绑定本轮 endpoint。');
    $revoke = $byId['revoke event credential'];
    if (($revoke['fixture_capture_ids'] ?? null) !== ['credential_id']
        || ($revoke['request_id'] ?? null) !== '${prefix}chain7-credential-revoke') {
        throw new RuntimeException('触发真实事件的本轮凭证必须通过正常撤销语义失效。');
    }
    $disabled = $byId['disabled is status two'];
    if (($disabled['assert']['json']['data.status'] ?? null) !== 2) throw new RuntimeException('Webhook 清理前必须证明 endpoint 已停用为 status=2。');
}

/** @return array{ok:bool,detail:string} */
function livePostgresReadonlyVerify(array $step, array $state): array
{
    $check = (string) ($step['verifier']['check'] ?? '');
    $definitions = livePostgresReadonlyChecks()[$check] ?? null;
    if ($definitions === null) throw new RuntimeException('postgres_readonly verifier 不在固定白名单。');
    if (getenv('SAND_IAM_ACCEPTANCE_DB_READONLY_VERIFY') !== '1' || getenv('SAND_IAM_ACCEPTANCE_DB_READONLY_CONFIRM') !== 'I_UNDERSTAND_THIS_READS_EXISTING_DATABASE') throw new RuntimeException('postgres_readonly verifier 需要显式只读数据库确认，未确认时不会连接数据库。');
    $capturedIds = $state['captured_ids'] ?? [];
    foreach ($definitions as $definition) if (!is_string($capturedIds[$definition['capture']] ?? null) || $capturedIds[$definition['capture']] === '') throw new RuntimeException('postgres_readonly verifier 缺少本轮已捕获 ID。');
    $mock = $GLOBALS['sand_iam_live_postgres_readonly_verifier'] ?? null;
    if (is_callable($mock)) {
        $counts = $mock($check, $definitions, $capturedIds);
        if (!is_array($counts)) throw new RuntimeException('postgres_readonly mock 返回无效。');
    } else {
        $dsn = (string) getenv('SAND_IAM_ACCEPTANCE_POSTGRES_READONLY_DSN');
        $user = (string) getenv('SAND_IAM_ACCEPTANCE_POSTGRES_READONLY_USER');
        $password = (string) getenv('SAND_IAM_ACCEPTANCE_POSTGRES_READONLY_PASSWORD');
        if (!str_starts_with($dsn, 'pgsql:') || $user === '' || $password === '') throw new RuntimeException('postgres_readonly verifier 缺少 PostgreSQL 只读 DSN/账户；不会连接数据库。');
        $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
        try {
            $pdo->exec('BEGIN TRANSACTION READ ONLY');
            $pdo->exec("SET LOCAL statement_timeout = '5000ms'");
            $counts = [];
            foreach ($definitions as $definition) {
                // Both identifiers originate only from the immutable registry above.
                $statement = $pdo->prepare('SELECT count(*) FROM ' . $definition['table'] . ' WHERE ' . $definition['column'] . ' = :fixture_id');
                $statement->execute(['fixture_id' => $capturedIds[$definition['capture']]]);
                $counts[$definition['table'] . '.' . $definition['column']] = (int) $statement->fetchColumn();
            }
            $pdo->rollBack();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }
    foreach ($definitions as $definition) {
        $key = $definition['table'] . '.' . $definition['column'];
        if (!isset($counts[$key]) || (int) $counts[$key] !== 0) return ['ok' => false, 'detail' => 'PostgreSQL 只读零残留检查仍发现本轮夹具。'];
    }
    return ['ok' => true, 'detail' => 'PostgreSQL 只读零残留检查通过。'];
}

/** @return array<string,mixed> */
function liveRun(string $chainId, array $chain, array $targets, array $authorizations, string $prefix, array $requirements): array
{
    if (!isset($requirements[$chainId])) throw new RuntimeException('验收清单未定义该链的凭证槽。');
    liveValidateChainPlan($chainId, $chain, $requirements, $targets);
    $variables = ['prefix' => $prefix, 'chain' => str_replace('-', '_', $chainId)];
    $fixtures = []; $capturedIds = []; $checks = []; $sequence = 0;
    $cookieJars = [];
    $execute = static function (array $step) use (&$variables, &$fixtures, &$capturedIds, &$checks, &$sequence, $targets, $authorizations, $prefix, &$cookieJars): bool {
        if (!liveShouldRunStep($step, $variables)) return true;
        $step = liveDeriveBody(liveInterpolate($step, $variables), $variables);
        $auth = (string) ($step['auth'] ?? '');
        $target = $targets[(string) $step['target']];
        if ($target['kind'] === 'external') {
            if ($auth !== 'none') throw new RuntimeException("外部 step 不得使用 SandIAM 凭证：{$auth}。");
            $authorization = '';
        } else {
            if ($auth === 'none') {
                $authorization = '';
            } elseif (isset($step['auth_capture'])) {
                $captured = $variables[$step['auth_capture']] ?? null;
                if (!is_string($captured) || $captured === '') throw new RuntimeException('auth_capture 尚未在本轮成功响应中捕获。');
                $authorization = 'Authorization: Bearer ' . $captured;
            } else {
                if (!isset($authorizations[$auth])) throw new RuntimeException("step 使用未知凭证：{$auth}。");
                $authorization = $authorizations[$auth];
            }
        }
        $cookieJar = null;
        if ($target['kind'] === 'sandiam') {
            $cookieKey = (string) $step['target'] . '|credential:' . hash('sha256', $authorization);
            if (!isset($cookieJars[$cookieKey])) {
                $cookieJars[$cookieKey] = tempnam(sys_get_temp_dir(), 'sand_iam_live_cookie_');
                if ($cookieJars[$cookieKey] === false) throw new RuntimeException('无法创建隔离的 SandIAM 会话存储。');
            }
            $cookieJar = $cookieJars[$cookieKey];
        }
        if (isset($step['request_id']) && (!is_string($step['request_id']) || preg_match(SAND_IAM_ACCEPTANCE_FIXTURE_REQUEST_ID_PATTERN, $step['request_id']) !== 1 || !str_starts_with($step['request_id'], $prefix))) {
            throw new RuntimeException('计划中的 request_id 必须以本轮完整夹具前缀开头且使用安全后缀。');
        }
        $poll = livePollDefinition($step);
        $polled = liveRunPoll(
            static function () use ($target, $step, $authorization, $prefix, &$sequence, $cookieJar): array {
                return liveHttp($target, $step, $authorization, $prefix . 'live_' . (++$sequence), $cookieJar);
            },
            static fn (array $response): array => liveCheckResponse($response, $step),
            $poll,
        );
        $response = $polled['response'];
        $outcome = $polled['outcome'];
        $pollDetail = $poll['max_attempts'] === 1 ? '' : "（第 {$polled['attempts']}/{$poll['max_attempts']} 次）";
        $checks[] = ['label' => (string) $step['id'], 'ok' => $outcome['ok'], 'detail' => $outcome['detail'] . $pollDetail];
        if (!$outcome['ok']) return false;
        foreach (($step['capture'] ?? []) as $name => $source) {
            if (!is_string($name) || !is_array($source) || !is_string($source['from'] ?? null) || !is_string($source['path'] ?? null)) throw new RuntimeException('capture 必须声明 from/path。');
            $value = match ($source['from']) {
                'json' => liveJsonPath($response['json'], $source['path']),
                'json_query' => (static function () use ($response, $source): string {
                    $uri = liveJsonPath($response['json'], $source['path']);
                    if (!is_string($uri) || !is_string($source['query'] ?? null)) throw new RuntimeException('json_query 必须声明 URI 路径和查询参数名。');
                    parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
                    $value = $query[$source['query']] ?? null;
                    if (!is_scalar($value) || $value === '') throw new RuntimeException('JSON URI 缺少 capture 查询参数。');
                    return (string) $value;
                })(),
                'header' => implode(', ', $response['headers'][strtolower($source['path'])] ?? []),
                'location' => $response['location'],
                'location_query' => (static function () use ($response, $source): string {
                    parse_str((string) parse_url($response['location'], PHP_URL_QUERY), $query);
                    $value = $query[$source['path']] ?? null;
                    if (!is_scalar($value) || $value === '') throw new RuntimeException('Location 缺少 capture 查询参数。');
                    return (string) $value;
                })(),
                default => throw new RuntimeException('capture 来源只允许 json/json_query/header/location/location_query。'),
            };
            if (isset($source['derive'])) {
                if (($source['derive'] ?? null) !== 'totp_sha1_6' || !is_string($value)) throw new RuntimeException('capture derive 只允许从当前响应的 TOTP 密钥安全生成六位验证码。');
                $variables['__totp_counter_' . hash('sha256', $value)] = intdiv(time(), 30);
                $value = liveTotpCode($value);
            }
            if (is_array($value) || $value === '') throw new RuntimeException('capture 必须是非空标量。');
            $sensitive = liveCaptureNameSensitive($name);
            if ($sensitive && ($source['sensitive'] ?? false) !== true) throw new RuntimeException('敏感 capture 必须明确标为 sensitive，且不会写入证据。');
            $variables[$name] = $value;
            if (!$sensitive && str_ends_with($name, '_id')) { $fixtures[] = (string) $value; $capturedIds[$name] = (string) $value; }
        }
        return true;
    };
    $successful = true;
    $cleanupOk = true;
    $unreconciledWriteOutcome = false;
    $cleanupPhysicalExecuted = 0;
    $cleanupStatusExecuted = 0;
    $writeNeedsReconciliation = static function (array $step) use (&$variables): bool {
        if (($step['write'] ?? null) !== true) return false;
        $captureIds = $step['fixture_capture_ids'] ?? array_values(array_filter(
            array_keys(is_array($step['capture'] ?? null) ? $step['capture'] : []),
            static fn (string $name): bool => str_ends_with($name, '_id'),
        ));
        return is_array($captureIds)
            && array_filter(
                $captureIds,
                static fn (mixed $id): bool => is_string($id)
                    && (!isset($variables[$id]) || $variables[$id] === ''),
            ) !== [];
    };
    try {
        foreach ($chain['steps'] as $step) {
            try {
                if (!$execute($step)) {
                    if ($writeNeedsReconciliation($step)) $unreconciledWriteOutcome = true;
                    $successful = false;
                    break;
                }
            } catch (Throwable $exception) {
                if ($writeNeedsReconciliation($step)) $unreconciledWriteOutcome = true;
                $checks[] = ['label' => (string) ($step['id'] ?? 'step'), 'ok' => false, 'detail' => '业务步骤异常：' . $exception->getMessage()];
                $successful = false;
                break;
            }
        }
        // Cleanup is intentionally independent: a failed business step must not
        // hide a later cleanup attempt or turn a disabled record into “zero residual”.
        foreach ($chain['cleanup']['steps'] as $step) {
            if (!liveShouldRunStep($step, $variables)) continue;
            try {
                if (is_array($step['verifier'] ?? null) && ($step['verifier']['kind'] ?? null) === 'postgres_readonly') {
                    $outcome = livePostgresReadonlyVerify($step, ['captured_ids' => $capturedIds]);
                    $checks[] = ['label' => (string) $step['id'], 'ok' => $outcome['ok'], 'detail' => $outcome['detail']];
                    if (!$outcome['ok']) $cleanupOk = false;
                } elseif (!$execute($step)) {
                    $cleanupOk = false;
                }
                if (($step['proof'] ?? null) === 'physical_cleanup') $cleanupPhysicalExecuted++;
                if (($step['proof'] ?? null) === 'zero_residual') $cleanupStatusExecuted++;
            } catch (Throwable $exception) {
                if ($writeNeedsReconciliation($step)) $unreconciledWriteOutcome = true;
                $checks[] = ['label' => (string) ($step['id'] ?? 'cleanup'), 'ok' => false, 'detail' => '清理步骤异常：' . $exception->getMessage()];
                $cleanupOk = false;
            }
        }
    } finally { foreach ($cookieJars as $cookieJar) @unlink($cookieJar); }
    $c01V2 = $chainId === 'organization-application-environment'
        && in_array(2, array_map(static fn (mixed $step): int => (int) (($step['body']['contract_version'] ?? 0)), $chain['cleanup']['steps'] ?? []), true);
    $cleanupState = $cleanupOk ? 'confirmed' : 'failed';
    if ($c01V2 && ($cleanupPhysicalExecuted === 0 || $cleanupStatusExecuted === 0)) {
        $cleanupOk = false;
        $cleanupState = 'not_confirmed';
        $checks[] = ['label' => 'C01 cleanup confirmation', 'ok' => false, 'detail' => 'C01 未执行匹配的物理清理和零残留状态检查；夹具状态未确认。'];
    }
    if ($unreconciledWriteOutcome) {
        $cleanupOk = false;
        $cleanupState = 'not_confirmed';
        $checks[] = ['label' => 'write outcome reconciliation', 'ok' => false, 'detail' => '写请求未返回全部夹具编号，无法证明自动清理覆盖了可能已提交的对象；夹具状态未确认。'];
    }
    $passed = count(array_filter($checks, static fn (array $check): bool => $check['ok']));
    $status = $successful && $cleanupOk && $passed === count($checks)
        ? 'passed'
        : ($cleanupState === 'not_confirmed' ? 'blocked' : 'failed');
    return ['id' => $chainId, 'checks' => $checks, 'fixture_ids' => array_values(array_unique($fixtures)), 'captured_ids' => $capturedIds, 'cleanup' => ['ok' => $cleanupOk, 'state' => $cleanupState, 'detail' => $cleanupOk ? '自动清理和零残留查询均已通过。' : ($cleanupState === 'not_confirmed' ? '未执行匹配的自动清理和零残留检查，状态未确认。' : '自动清理或零残留查询失败。')], 'passed' => $passed, 'total' => count($checks), 'status' => $status];
}

if (!defined('SAND_IAM_LIVE_DRIVER_LIBRARY')) try {
    $options = liveOptions($argv);
    $prefix = livePrefix();
    $host = rtrim(liveRequire('SAND_IAM_ACCEPTANCE_HOST_URL'), '/');
    $plan = livePlan();
    $manifest = require __DIR__ . '/terminal-acceptance-manifest.php';
    $requirements = [];
    foreach (($manifest['chains'] ?? []) as $id => $definition) if (is_string($id) && is_array($definition) && is_array($definition['live_credential_slots'] ?? null)) $requirements[$id] = array_values($definition['live_credential_slots']);
    $targets = []; foreach ($plan['targets'] as $name => $target) { if (!is_string($name) || !is_array($target)) throw new RuntimeException('target 声明不安全。'); $targets[$name] = liveSafeTarget($target, $host); }
    if (getenv('SAND_IAM_ACCEPTANCE_LIVE') !== '1' || getenv('SAND_IAM_ACCEPTANCE_ALLOW_DATABASE_WRITES') !== '1' || getenv('SAND_IAM_ACCEPTANCE_CONFIRM') !== 'I_UNDERSTAND_THIS_WRITES_TEST_FIXTURES') throw new RuntimeException('live driver 需要 SAND_IAM_ACCEPTANCE_LIVE=1、SAND_IAM_ACCEPTANCE_ALLOW_DATABASE_WRITES=1 和 SAND_IAM_ACCEPTANCE_CONFIRM=I_UNDERSTAND_THIS_WRITES_TEST_FIXTURES。');
    if (!isset($plan['chains'][$options['chain']]) || !is_array($plan['chains'][$options['chain']])) throw new RuntimeException('live plan 未定义所选业务链。');
    $requiredSlots = $plan['chains'][$options['chain']]['required_credentials'] ?? []; if (!is_array($requiredSlots)) throw new RuntimeException('live 链未声明凭证槽。');
    // Validate the selected chain, including automatic cleanup, before the
    // ownership gate or credential loading can lead to a host request.
    liveValidateChainPlan($options['chain'], $plan['chains'][$options['chain']], $requirements, $targets);
    livePreflightGate($plan, $prefix, $options['chain'], $plan['chains'][$options['chain']]);
    $report = liveRun($options['chain'], $plan['chains'][$options['chain']], $targets, liveAuthorizations($requiredSlots), $prefix, $requirements);
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    exit($report['status'] === 'passed' ? 0 : LIVE_EXIT_FAILED);
} catch (Throwable $exception) {
    fwrite(STDERR, 'SandIAM live driver blocked: ' . $exception->getMessage() . "\n");
    exit(LIVE_EXIT_BLOCKED);
}
