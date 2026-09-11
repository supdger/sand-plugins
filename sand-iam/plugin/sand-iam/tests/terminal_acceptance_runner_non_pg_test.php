<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

/** The seven-chain runner must remain usable without a host, service or database. */
$root = dirname(__DIR__, 3);
$runner = $root . '/tools/run-terminal-acceptance.php';
$manifest = $root . '/tools/terminal-acceptance-manifest.php';
$liveDriver = $root . '/tools/terminal-acceptance-live-driver.php';
$simulator = $root . '/tools/terminal-acceptance-local-simulator.php';
$acceptanceFixtureStore = $root . '/plugin/sand-iam/app/acceptance/AcceptanceFixtureStore.php';
$acceptanceFixtureService = $root . '/plugin/sand-iam/app/acceptance/AcceptanceFixtureService.php';
$acceptanceFixtureDatabaseStoreTest = $root . '/plugin/sand-iam/tests/acceptance_fixture_database_store_non_pg_test.php';
const TERMINAL_ACCEPTANCE_MOCK_PREFIX = 'sand_iam_acceptance_abcdef0123456789_';

function terminalAcceptanceFail(string $message): never
{
    fwrite(STDERR, "terminal acceptance runner non-PG test failed: {$message}\n");
    exit(1);
}

function terminalAcceptanceRun(array $arguments): array
{
    global $runner;
    $pipes = [];
    $process = proc_open(array_merge([PHP_BINARY, $runner], $arguments), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) terminalAcceptanceFail('runner could not be started');
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

function terminalAcceptanceScript(string $script, array $arguments = []): array
{
    $pipes = [];
    $process = proc_open(array_merge([PHP_BINARY, $script], $arguments), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) terminalAcceptanceFail('script could not be started');
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

if (!is_file($runner) || !is_file($manifest) || !is_file($liveDriver) || !is_file($simulator)) terminalAcceptanceFail('runner, manifest, live driver or simulator is missing');
$inventory = require $manifest;
if (!isset($inventory['chains']) || count($inventory['chains']) !== 7) terminalAcceptanceFail('manifest does not expose seven chains');
foreach ($inventory['chains'] as $id => $chain) {
    if (!is_string($id) || !isset($chain['contracts'], $chain['cleanup'], $chain['live_prerequisites'], $chain['live_credential_slots']) || !is_array($chain['live_credential_slots']) || $chain['live_credential_slots'] === []) terminalAcceptanceFail("incomplete chain declaration: {$id}");
}

$listed = terminalAcceptanceRun(['--list']);
if ($listed['code'] !== 0 || substr_count($listed['stdout'], "\n") !== 7 || !str_contains($listed['stdout'], 'event-webhook-delivery')) {
    terminalAcceptanceFail('list output is incomplete');
}

$reportPath = tempnam(sys_get_temp_dir(), 'sand_iam_acceptance_');
if ($reportPath === false) terminalAcceptanceFail('cannot allocate temporary JSON evidence path');
try {
    $simulated = terminalAcceptanceRun(['--mode=simulated', '--json=' . $reportPath]);
    if ($simulated['code'] !== 0) terminalAcceptanceFail('in-memory simulation failed: ' . $simulated['stderr']);
    $report = json_decode((string) file_get_contents($reportPath), true);
    if (!is_array($report) || ($report['mode'] ?? null) !== 'simulated' || ($report['host_or_database_touched'] ?? true) !== false || ($report['service_started'] ?? true) !== false) {
        terminalAcceptanceFail('simulation safety report is invalid');
    }
    if (($report['status'] ?? null) !== 'passed' || count($report['chains'] ?? []) !== 7 || ($report['passed'] ?? 0) !== ($report['total'] ?? -1)) {
        terminalAcceptanceFail('simulation did not pass every chain');
    }
    foreach ($report['chains'] as $chain) {
        if (($chain['cleanup']['ok'] ?? false) !== true || ($chain['fixture_ids'] ?? []) === []) terminalAcceptanceFail('chain did not preserve fixture and cleanup evidence');
    }
    $simulatedChainSeven = array_values(array_filter($report['chains'], static fn (mixed $chain): bool => is_array($chain) && ($chain['id'] ?? null) === 'event-webhook-delivery'))[0] ?? [];
    $simulatedChainSevenFixtures = $simulatedChainSeven['fixture_ids'] ?? [];
    if (($simulatedChainSeven['cleanup']['ok'] ?? false) !== true
        || count(array_filter($simulatedChainSevenFixtures, static fn (mixed $fixture): bool => is_string($fixture) && str_starts_with($fixture, 'webhook_endpoint:'))) !== 2
        || count(array_filter($simulatedChainSevenFixtures, static fn (mixed $fixture): bool => is_string($fixture) && str_starts_with($fixture, 'webhook_delivery:'))) !== 2
        || !str_contains((string) ($simulatedChainSeven['cleanup']['detail'] ?? ''), '零残留')) {
        terminalAcceptanceFail('simulated Chain7 report does not expose all child fixtures and zero-residual cleanup evidence');
    }
    $guarded = terminalAcceptanceRun(['--mode=live']);
    if ($guarded['code'] !== 2 || !str_contains($guarded['stderr'], 'SAND_IAM_ACCEPTANCE_LIVE')) terminalAcceptanceFail('live mode is not safely gated');
    $driverSource = (string) file_get_contents($liveDriver);
    foreach (['sand-iam.acceptance-live-plan/v2', 'SAND_IAM_ACCEPTANCE_PLATFORM_ADMIN_AUTHORIZATION', 'SAND_IAM_ACCEPTANCE_SCOPED_ADMIN_AUTHORIZATION', 'SAND_IAM_ACCEPTANCE_OUT_OF_SCOPE_ADMIN_AUTHORIZATION', 'SAND_IAM_ACCEPTANCE_APPLICATION_USER_AUTHORIZATION', 'SAND_IAM_ACCEPTANCE_SERVICE_CLIENT_AUTHORIZATION', 'liveValidateChainPlan', 'livePreflightGate', 'SAND_IAM_ACCEPTANCE_FIXTURE_OWNERSHIP_CONFIRM', 'SAND_IAM_ACCEPTANCE_AUTOMATED_CLEANUP_CONFIRM', 'SAND_IAM_ACCEPTANCE_AUTOMATED_CLEANUP_EVIDENCE', 'zero_residual', 'api_available', 'CURLOPT_COOKIEJAR', 'session_scope 不允许由计划自定义', 'cookieKey', 'location_query', 'json_query', 'manual_sql_cleanup', 'postgres_readonly', 'SAND_IAM_ACCEPTANCE_DB_READONLY_VERIFY', 'BEGIN TRANSACTION READ ONLY', 'statement_timeout', 'liveAllowedPath', 'curl_init'] as $fragment) {
        if (!str_contains($driverSource, $fragment)) terminalAcceptanceFail("live driver misses {$fragment}");
    }
    foreach (['oauthRpCallback', 'casClient', 'provider', 'endpoint', 'event', 'worker', 'receiver', 'retry', 'proof', 'cleanup', 'simulatorRequest', 'simulatorReadRequest', 'simulatorReaderSelfTest', 'MAX_POSITIVE_ID', 'endpoint_id must be a bounded positive integer', 'request body incomplete', 'request body too large', '/acceptance-fixture/webhook/delivery/worker', 'x-sandiam-timestamp', 'hash_equals', 'stream_socket_server', 'SAND_IAM_ACCEPTANCE_SIMULATOR_TLS_CERT', 'SAND_IAM_ACCEPTANCE_SIMULATOR_SESSION', 'content-length'] as $fragment) {
        if (!str_contains((string) file_get_contents($simulator), $fragment)) terminalAcceptanceFail("local simulator misses {$fragment}");
    }
    $runnerSource = (string) file_get_contents($runner);
    if (!str_contains($runnerSource, '--chain3-protocol') || str_contains($runnerSource, '$active = true; $active = false;')) terminalAcceptanceFail('simulated Chain3 runner still assembles a session result instead of invoking the controlled fixture protocol');
    $chainThreeProtocol = terminalAcceptanceScript($simulator, ['--chain3-protocol']);
    $chainThree = json_decode($chainThreeProtocol['stdout'], true);
    $emptyHumanResidual = ['identity' => [], 'identity_auth' => [], 'auth_session' => [], 'auth_refresh_token' => [], 'mfa_factor' => [], 'mfa_recovery_code' => []];
    if ($chainThreeProtocol['code'] !== 0 || !is_array($chainThree)
        || count($chainThree['actual']['identity'] ?? []) !== 1
        || count($chainThree['actual']['auth_session'] ?? []) !== 3
        || count($chainThree['actual']['mfa_factor'] ?? []) !== 1
        || ($chainThree['residual'] ?? null) !== $emptyHumanResidual
        || ($chainThree['cleanup']['ok'] ?? false) !== true
        || ($chainThree['drain_preserved'] ?? false) !== true
        || (($chainThree['drain_snapshot']['before'] ?? null) !== ($chainThree['drain_snapshot']['after'] ?? null))
        || array_keys($chainThree['drain_snapshot']['before'] ?? []) !== ['identity_auth', 'auth_session', 'auth_refresh_token', 'mfa_factor', 'mfa_recovery_code']
        || ($chainThree['replay']['replayed'] ?? false) !== true) terminalAcceptanceFail('Chain3 protocol does not prove controlled lifecycle cleanup, DRAIN_REQUIRED and retry semantics');
    if (!str_contains($runnerSource, '--chain7-protocol') || str_contains($runnerSource, 'identity.changed') || str_contains($runnerSource, '1700000000')) terminalAcceptanceFail('simulated Chain7 runner still uses a static webhook fixture instead of the controlled protocol');
    if (!str_contains($runnerSource, '--chain5-protocol') || str_contains($runnerSource, "\$validPkce = hash_equals(hash('sha256', 'verifier')")) terminalAcceptanceFail('simulated Chain5 runner still assembles OAuth/CAS results instead of invoking the controlled protocol');
    $chainFiveProtocol = terminalAcceptanceScript($simulator, ['--chain5-protocol']);
    $chainFive = json_decode($chainFiveProtocol['stdout'], true);
    if ($chainFiveProtocol['code'] !== 0 || !is_array($chainFive)
        || ($chainFive['protocol']['access_allowed'] ?? false) !== true
        || ($chainFive['protocol']['wrong_pkce_denied'] ?? false) !== true
        || ($chainFive['protocol']['cas_validated'] ?? false) !== true
        || ($chainFive['policy_allow'] ?? false) !== true
        || ($chainFive['oauth_revoked'] ?? false) !== true
        || ($chainFive['policy_and_route_denied'] ?? false) !== true
        || ($chainFive['scope_guards'] ?? []) !== [
            'missing_application' => true,
            'disabled_application' => true,
            'missing_environment' => true,
            'wrong_application_environment' => true,
            'missing_resource' => true,
            'wrong_application_resource' => true,
        ]
        || ($chainFive['cleanup']['ok'] ?? false) !== true
        || ($chainFive['replay']['replayed'] ?? false) !== true
        || array_filter((array) ($chainFive['residual'] ?? [])) !== []) terminalAcceptanceFail('Chain5 protocol does not prove OAuth/CAS configuration, governance allow/deny, revocation and zero-residual cleanup');
    $chainSevenProtocol = terminalAcceptanceScript($simulator, ['--chain7-protocol']);
    $chainSeven = json_decode($chainSevenProtocol['stdout'], true);
    if ($chainSevenProtocol['code'] !== 0 || !is_array($chainSeven)) terminalAcceptanceFail('Chain7 protocol did not return machine-readable state evidence');
    $actualWebhookFixtures = $chainSeven['actual'] ?? [];
    $cleanedWebhookFixtures = $chainSeven['cleanup'] ?? [];
    $residualWebhookFixtures = $chainSeven['residual'] ?? [];
    $cleanupEffects = array_values(array_filter((array) ($chainSeven['effects'] ?? []), static fn (mixed $effect): bool => is_array($effect) && ($effect['kind'] ?? null) === 'webhook_fixture_cleanup'));
    $effectEndpointIds = array_map(static fn (array $effect): int => (int) ($effect['endpoint_id'] ?? 0), $cleanupEffects);
    $effectDeliveryIds = array_map(static fn (array $effect): string => (string) ($effect['delivery_id'] ?? ''), $cleanupEffects);
    sort($effectEndpointIds, SORT_NUMERIC); sort($effectDeliveryIds, SORT_STRING);
    if (($actualWebhookFixtures['endpoint_ids'] ?? null) !== [1, 2]
        || !is_array($actualWebhookFixtures['delivery_ids'] ?? null) || count($actualWebhookFixtures['delivery_ids']) !== 2
        || ($actualWebhookFixtures['endpoint_ids'] ?? null) !== ($cleanedWebhookFixtures['endpoint_ids'] ?? null)
        || ($actualWebhookFixtures['delivery_ids'] ?? null) !== ($cleanedWebhookFixtures['delivery_ids'] ?? null)
        || ($actualWebhookFixtures['endpoint_ids'] ?? null) !== $effectEndpointIds
        || ($actualWebhookFixtures['delivery_ids'] ?? null) !== $effectDeliveryIds
        || $residualWebhookFixtures !== ['endpoint_ids' => [], 'delivery_ids' => []]
        || ($cleanedWebhookFixtures['zero_residual'] ?? false) !== true
        || ($cleanedWebhookFixtures['ok'] ?? false) !== true) {
        terminalAcceptanceFail('Chain7 protocol does not prove every created endpoint and delivery was cleaned with zero residual');
    }
    $exampleSource = (string) file_get_contents($root . '/tools/fixtures/terminal-acceptance-live-plan.example.json');
    $examplePlan = json_decode($exampleSource, true);
    if (!is_array($examplePlan) || ($examplePlan['format'] ?? null) !== 'sand-iam.acceptance-live-plan/v2' || count($examplePlan['chains'] ?? []) !== 7 || !is_array($examplePlan['targets'] ?? null) || !str_contains($exampleSource, '__REQUIRED_')) terminalAcceptanceFail('live plan example is not safely incomplete');
    foreach ($examplePlan['chains'] as $id => $chain) {
        if (!is_array($chain['required_credentials'] ?? null) || !is_array($chain['steps'] ?? null) || !is_array($chain['cleanup']['steps'] ?? null)) terminalAcceptanceFail("live plan lacks role/step/cleanup declaration: {$id}");
        $proofs = array_column($chain['steps'], 'proof');
        foreach (['create', 'allow', 'deny', 'audit', 'revoke_or_recover', 'revoke_effective'] as $proof) if (!in_array($proof, $proofs, true)) terminalAcceptanceFail("live plan lacks {$proof} proof: {$id}");
        if (!in_array('zero_residual', array_column($chain['cleanup']['steps'], 'proof'), true)) terminalAcceptanceFail("live plan lacks zero residual proof: {$id}");
        foreach (array_merge($chain['steps'], $chain['cleanup']['steps']) as $step) {
            if (($step['verifier']['kind'] ?? null) === 'postgres_readonly') {
                if (($step['proof'] ?? null) !== 'zero_residual' || !is_string($step['verifier']['check'] ?? null)) terminalAcceptanceFail("postgres readonly verifier is incomplete: {$id}");
                continue;
            }
            if (!is_array($step['assert'] ?? null) || ($step['assert'] ?? []) === []) terminalAcceptanceFail("live plan accepts an assertion-free step: {$id}");
        }
    }
    if (!is_array($examplePlan['preflight']['fixture_ownership'] ?? null) || !is_array($examplePlan['preflight']['physical_cleanup'] ?? null)) terminalAcceptanceFail('live plan lacks structured preflight gates');
    foreach ($examplePlan['targets'] as $name => $target) {
        if ($name === 'sandiam') {
            if (($target['kind'] ?? null) !== 'sandiam') terminalAcceptanceFail('SandIAM target kind is missing');
            continue;
        }
        if (($target['kind'] ?? null) !== 'external') terminalAcceptanceFail("external target kind is missing: {$name}");
    }
    foreach ($examplePlan['chains'] as $id => $chain) foreach (array_merge($chain['steps'], $chain['cleanup']['steps']) as $step) {
        if (($step['verifier']['kind'] ?? null) === 'postgres_readonly') continue;
        $target = $examplePlan['targets'][$step['target']] ?? [];
        if (($target['kind'] ?? null) === 'external' && (($step['auth'] ?? null) !== 'none' || array_key_exists('Authorization', $step['headers'] ?? []))) terminalAcceptanceFail("external step leaks SandIAM authorization: {$id}/{$step['id']}");
        if (($target['kind'] ?? null) === 'sandiam' && str_starts_with((string) ($step['path'] ?? ''), '/app/sand-iam/admin/') && ($step['expect_http'] ?? null) === 200 && (($step['assert']['json']['code'] ?? null) !== 200)) terminalAcceptanceFail("admin success must assert SandAdmin code=200: {$id}/{$step['id']}");
    }
    $policyAllow = array_values(array_filter($examplePlan['chains']['identity-group-role-policy']['steps'], static fn (array $step): bool => ($step['id'] ?? '') === 'authorization decision allows derived group role'))[0] ?? [];
    $policyDeny = array_values(array_filter($examplePlan['chains']['identity-group-role-policy']['steps'], static fn (array $step): bool => ($step['id'] ?? '') === 'authorization decision denies outside policy'))[0] ?? [];
    if (($policyAllow['path'] ?? null) !== '/api/sand-iam/v1/authorization/decide' || (($policyAllow['assert']['json']['data.allowed'] ?? null) !== true) || (($policyDeny['expect_http'] ?? null) !== 200 || (($policyDeny['assert']['json']['data.allowed'] ?? null) !== false))) terminalAcceptanceFail('policy allow/deny must use the audited runtime authorization decision');
    $webhookRetry = array_values(array_filter($examplePlan['chains']['event-webhook-delivery']['steps'], static fn (array $step): bool => ($step['id'] ?? '') === 'retry queues delivery'))[0] ?? [];
    if (($webhookRetry['expect_http'] ?? null) !== 200 || (($webhookRetry['assert']['json']['data'] ?? null) !== '投递任务已重新排队')) terminalAcceptanceFail('webhook retry must assert the controller return value');
    $webhookSteps = $examplePlan['chains']['event-webhook-delivery']['steps'];
    $webhookStep = static function (string $id) use ($webhookSteps): array {
        return array_values(array_filter($webhookSteps, static fn (array $step): bool => ($step['id'] ?? '') === $id))[0] ?? [];
    };
    $webhookTrigger = $webhookStep('trigger dedicated acceptance event');
    $webhookWorker = $webhookStep('worker 500 recorded');
    $webhookStatus = $examplePlan['chains']['event-webhook-delivery']['cleanup']['steps'][1] ?? [];
    if (($webhookTrigger['path'] ?? null) !== '/app/sand-iam/admin/acceptance-fixture/webhook-event'
        || ($webhookWorker['path'] ?? null) !== '/app/sand-iam/admin/webhook/delivery/index?application_id=__REQUIRED_APPLICATION_ID__&webhook_endpoint_id=${webhook_id}&id=${delivery_id}&status=1'
        || ($webhookRetry['path'] ?? null) !== '/app/sand-iam/admin/webhook/delivery/retry'
        || !str_starts_with((string) ($webhookStatus['path'] ?? ''), '/app/sand-iam/admin/acceptance-fixture/status?chain_id=event-webhook-delivery&request_id=${prefix}chain7-status&')) {
        terminalAcceptanceFail('Chain7 trigger/worker/retry/status paths drifted from the frozen action contract');
    }
    $webhookAudit = $webhookStep('audit delivery');
    if (!str_contains((string) ($webhookAudit['path'] ?? ''), 'outcome=succeeded')
        || (($webhookAudit['assert']['json_contains']['data.data']['contains']['outcome'] ?? null) !== 'succeeded')
        || (($webhookAudit['assert']['json_contains']['data.data']['contains']['context']['contains']['delivery_state'] ?? null) !== 'delivered')) {
        terminalAcceptanceFail('Chain7 successful delivery audit must prove outcome=succeeded and delivery_state=delivered');
    }
    $disabled = $examplePlan['chains']['event-webhook-delivery']['steps'];
    if (($disabled[array_key_last($disabled)]['assert']['json']['data.status'] ?? null) !== 2) terminalAcceptanceFail('disabled webhook must assert status=2');
    $oauthSteps = $examplePlan['chains']['oauth-cas-api-governance']['steps'];
    $oauthStep = static function (string $id) use ($oauthSteps): array {
        return array_values(array_filter($oauthSteps, static fn (array $step): bool => ($step['id'] ?? null) === $id))[0] ?? [];
    };
    $oauthBind = $oauthStep('OAuth bind user session');
    $oauthConfirm = $oauthStep('OAuth confirm');
    $oauthToken = $oauthStep('correct PKCE token');
    $oauthWrong = $oauthStep('wrong PKCE invalid grant on independent code');
    $oauthWrongAuthorize = $oauthStep('OAuth authorize distinct wrong-PKCE request');
    $casConfirm = $oauthStep('CAS confirm');
    $casValidate = $oauthStep('CAS XML validate');
    if (($oauthBind['path'] ?? null) !== '/api/sand-iam/v1/oauth/interaction/session'
        || ($oauthConfirm['path'] ?? null) !== '/api/sand-iam/v1/oauth/interaction/confirm'
        || (($oauthConfirm['body']['decision'] ?? null) !== 'approve')
        || (($oauthConfirm['body']['csrf_token'] ?? null) !== '${oauth_csrf_token}')
        || (($oauthConfirm['capture']['oauth_code']['from'] ?? null) !== 'json_query')
        || (($oauthConfirm['capture']['oauth_code']['query'] ?? null) !== 'code')
        || (($oauthToken['body']['code_verifier'] ?? null) !== '__REQUIRED_PKCE_VERIFIER__')
        || (($oauthWrong['assert']['json']['error'] ?? null) !== 'invalid_grant')
        || !str_contains((string) ($oauthWrongAuthorize['path'] ?? ''), 'openid-nonce-two')
        || (($oauthWrong['body']['code'] ?? null) !== '${oauth_code_wrong}')
        || ($casConfirm['path'] ?? null) !== '/api/sand-iam/v1/cas/interaction/confirm'
        || !str_contains((string) ($casValidate['path'] ?? ''), '/cas/serviceValidate')
        || !str_contains((string) ($casValidate['assert']['headers']['content-type']['contains'] ?? ''), 'application/xml')) {
        terminalAcceptanceFail('OAuth/CAS example skips a required protocol response');
    }
    if (!str_contains($exampleSource, 'data.data') || !str_contains($exampleSource, 'resource_type') || !str_contains($exampleSource, 'resource_id')) terminalAcceptanceFail('audit example does not use the real pagination/filter shape');
    foreach ($examplePlan['chains'] as $id => $chain) {
        if (!is_string($chain['required_fixture_gate'] ?? null) || trim($chain['required_fixture_gate']) === '' || !is_string($chain['physical_cleanup_gate'] ?? null) || trim($chain['physical_cleanup_gate']) === '') terminalAcceptanceFail("live fixture/cleanup gate missing: {$id}");
        if (in_array($id, ['organization-application-environment', 'identity-group-role-policy', 'human-auth-session-mfa', 'workload-credential-invocation', 'oauth-cas-api-governance', 'delegation-scope', 'event-webhook-delivery'], true)) {
            $cleanupEvidence = $examplePlan['preflight']['physical_cleanup']['evidence']['chains'][$id] ?? null;
            if (($chain['cleanup']['api_available'] ?? null) !== true || isset($chain['manual_sql_cleanup']) || !is_array($cleanupEvidence) || !is_array($cleanupEvidence['action_contracts'] ?? null) || $cleanupEvidence['action_contracts'] === []) terminalAcceptanceFail("controlled cleanup contract missing: {$id}");
        } elseif (!is_array($chain['manual_sql_cleanup'] ?? null) || ($chain['cleanup']['api_available'] ?? null) !== false) {
            terminalAcceptanceFail("live gap/manual cleanup gate missing: {$id}");
        }
        foreach ($chain['cleanup']['steps'] as $step) if (($step['proof'] ?? null) === 'zero_residual' && str_contains((string) ($step['path'] ?? ''), 'status=1')) terminalAcceptanceFail("zero residual only checks active status: {$id}");
    }
    $humanChain = $examplePlan['chains']['human-auth-session-mfa'];
    $humanSteps = $humanChain['steps'];
    $humanStep = static function (string $id) use ($humanSteps): array {
        return array_values(array_filter($humanSteps, static fn (array $step): bool => ($step['id'] ?? null) === $id))[0] ?? [];
    };
    $register = $humanStep('register this-run application user');
    $login = $humanStep('login this run');
    $mfaStart = $humanStep('start MFA factor');
    $mfaConfirm = $humanStep('confirm MFA factor');
    $mfaLogin = $humanStep('login requires MFA challenge');
    $mfaVerify = $humanStep('verify MFA login challenge');
    $mfaVerifyAudit = $humanStep('audit MFA challenge verification ownership');
    $registrationDenied = $humanStep('registration revoked session denied');
    $loginDenied = $humanStep('new revoked session denied');
    $mfaDenied = $humanStep('MFA verified revoked session denied');
    $mfaRevoke = $humanStep('revoke MFA factor');
    $revokeEffectiveSteps = array_values(array_filter($humanSteps, static fn (array $step): bool => ($step['proof'] ?? null) === 'revoke_effective'));
    $hasCompleteHumanRevokeEvidence = static function (array $steps): bool {
        if (count($steps) !== 3) return false;
        $captures = array_column($steps, 'auth_capture');
        sort($captures);
        return $captures === ['login_session_token', 'mfa_login_session_token', 'registration_session_token']
            && array_unique(array_column($steps, 'path')) === ['/api/sand-iam/v1/auth/sessions']
            && array_unique(array_column($steps, 'expect_http')) === [401];
    };
    if (($register['auth'] ?? null) !== 'none'
        || ($register['path'] ?? null) !== '/api/sand-iam/v1/auth/register'
        || (($register['capture']['registration_session_id']['path'] ?? null) !== 'data.session_id')
        || (($login['auth'] ?? null) !== 'none'
        || ($login['path'] ?? null) !== '/api/sand-iam/v1/auth/login')
        || (($login['capture']['login_session_token']['path'] ?? null) !== 'data.access_token')
        || (($mfaStart['capture']['totp_code']['derive'] ?? null) !== 'totp_sha1_6')
        || (($mfaConfirm['body']['code'] ?? null) !== '${totp_code}')
        || (($mfaLogin['assert']['json']['data.mfa_required'] ?? null) !== true)
        || (($mfaLogin['write'] ?? null) !== true)
        || (($mfaLogin['effect'] ?? null) !== 'transient_auth_challenge')
        || (($mfaLogin['capture']['mfa_login_challenge_token']['path'] ?? null) !== 'data.challenge_token')
        || (($mfaVerify['path'] ?? null) !== '/api/sand-iam/v1/auth/mfa/challenge/verify')
        || (($mfaVerify['body_derivations']['code']['from_capture'] ?? null) !== 'totp_secret')
        || (($mfaVerify['body_derivations']['code']['derive'] ?? null) !== 'totp_sha1_6_next')
        || (($mfaVerify['capture']['mfa_login_session_id']['path'] ?? null) !== 'data.session_id')
        || !str_contains((string) ($mfaVerifyAudit['path'] ?? ''), 'action=identity.mfa_login_verify')
        || (($mfaVerifyAudit['assert']['json_contains']['data.data']['contains']['resource_type'] ?? null) !== 'mfa_challenge')
        || (($mfaVerifyAudit['assert']['json_contains']['data.data']['contains']['actor_ref'] ?? null) !== '${identity_id}')
        || (($mfaVerifyAudit['assert']['json_contains']['data.data']['contains']['application_id'] ?? null) !== '__REQUIRED_APPLICATION_ID__')
        || (($registrationDenied['auth_capture'] ?? null) !== 'registration_session_token')
        || (($loginDenied['auth_capture'] ?? null) !== 'login_session_token')
        || (($mfaDenied['auth_capture'] ?? null) !== 'mfa_login_session_token')
        || (($registrationDenied['path'] ?? null) !== '/api/sand-iam/v1/auth/sessions')
        || (($loginDenied['path'] ?? null) !== '/api/sand-iam/v1/auth/sessions')
        || (($mfaDenied['path'] ?? null) !== '/api/sand-iam/v1/auth/sessions')
        || (($registrationDenied['expect_http'] ?? null) !== 401 || ($loginDenied['expect_http'] ?? null) !== 401 || ($mfaDenied['expect_http'] ?? null) !== 401)
        || !$hasCompleteHumanRevokeEvidence($revokeEffectiveSteps)
        || $hasCompleteHumanRevokeEvidence(array_slice($revokeEffectiveSteps, 0, 1))
        || (($mfaRevoke['body']['factor_id'] ?? null) !== '${mfa_factor_id}')) terminalAcceptanceFail('human-auth chain does not bind registration, login and TOTP confirmation to this-run credentials');
    $delegationCreate = array_values(array_filter(
        $examplePlan['chains']['delegation-scope']['steps'],
        static fn (array $step): bool => ($step['id'] ?? null) === 'create delegation',
    ))[0] ?? [];
    if (($delegationCreate['body']['admin_id'] ?? null) !== null || ($delegationCreate['body']['admin_user_id'] ?? null) !== '__REQUIRED_SCOPED_ADMIN_ID__') terminalAcceptanceFail('delegation must use real admin_user_id field');
    $groupChain = $examplePlan['chains']['identity-group-role-policy'];
    $groupCleanup = $groupChain['cleanup']['steps'] ?? [];
    $groupCleanupContracts = $examplePlan['preflight']['physical_cleanup']['evidence']['chains']['identity-group-role-policy']['action_contracts'] ?? null;
    if (($groupChain['cleanup']['api_available'] ?? null) !== true || isset($groupChain['manual_sql_cleanup'])
        || count($groupCleanup) !== 8
        || !is_array($groupCleanupContracts)
        || count($groupCleanupContracts) !== 4
        || (($groupCleanup[0]['proof'] ?? null) !== 'physical_cleanup')
        || (($groupCleanup[1]['proof'] ?? null) !== 'zero_residual')
        || (($groupCleanup[0]['body']['role_id'] ?? null) !== '__REQUIRED_ROLE_ID__')
        || !str_contains((string) ($groupCleanup[1]['path'] ?? ''), 'role_id=__REQUIRED_ROLE_ID__')
        || (($groupCleanup[0]['body']['object_ids']['identity_group_role'][0] ?? null) !== '${group_role_relation_id}')
        || (($groupCleanup[0]['body']['object_request_ids']['identity_group_member'][0] ?? null) !== '${prefix}chain2-member-add')
        || (($groupCleanup[2]['run_unless_capture_ids'] ?? null) !== ['group_role_relation_id'])
        || (($groupCleanup[6]['run_unless_capture_ids'] ?? null) !== ['group_id'])) {
        terminalAcceptanceFail('group role chain does not bind all captured fixtures to controlled cleanup and matching partial recovery');
    }
    $memberAdd = array_values(array_filter($groupChain['steps'], static fn (array $step): bool => ($step['id'] ?? null) === 'add this-run identity to group'))[0] ?? [];
    $groupRoleGrant = array_values(array_filter($groupChain['steps'], static fn (array $step): bool => ($step['id'] ?? null) === 'grant role to group'))[0] ?? [];
    if (($memberAdd['path'] ?? null) !== '/app/sand-iam/admin/identity-group/member/add'
        || (($memberAdd['body']['identity_id'] ?? null) !== '${identity_id}')
        || (($groupRoleGrant['capture']['group_role_relation_id']['path'] ?? null) !== 'data.id')
        || str_contains(json_encode($groupChain, JSON_THROW_ON_ERROR), '__REQUIRED_GROUP_ROLE_ID__')) {
        terminalAcceptanceFail('group role plan does not join this-run identity or revoke captured relation');
    }
    $grant = array_values(array_filter($groupChain['steps'], static fn (array $step): bool => ($step['id'] ?? '') === 'grant role to group'))[0] ?? [];
    $revoke = array_values(array_filter($groupChain['steps'], static fn (array $step): bool => ($step['id'] ?? '') === 'revoke group role'))[0] ?? [];
    if (($grant['capture']['group_role_relation_id']['path'] ?? null) !== 'data.id' || (($revoke['body']['id'] ?? null) !== '${group_role_relation_id}')) terminalAcceptanceFail('group role grant/revoke fields do not match controller response');
    if (str_contains($exampleSource, 'policy.simulate')) terminalAcceptanceFail('group-chain audit cannot claim that read-only policy simulation writes audit');
    foreach (['identity_group.member_add', 'identity_group_role.grant', 'authorize.__REQUIRED_ALLOW_OPERATION__', 'authorize.__REQUIRED_DENY_OPERATION__'] as $action) {
        $audits = array_values(array_filter($groupChain['steps'], static fn (array $step): bool =>
            str_contains((string) ($step['path'] ?? ''), 'action=' . $action)
            && (($step['assert']['json_contains']['data.data']['contains']['action'] ?? null) === $action)
        ));
        if (count($audits) !== 1) terminalAcceptanceFail("group-chain audit omits real action {$action}");
    }
    $sessionChain = $humanSteps;
    $sessionRevokeIndex = array_search('revoke login session', array_column($sessionChain, 'id'), true);
    $sessionAuditIndex = array_search('audit this-run session revoke', array_column($sessionChain, 'id'), true);
    if ($sessionRevokeIndex === false || $sessionAuditIndex === false || $sessionAuditIndex <= $sessionRevokeIndex || !str_contains($exampleSource, 'action=identity.session_revoke') || !str_contains($exampleSource, 'resource_type=auth_session') || !str_contains($exampleSource, 'request_id=${prefix}chain3-login-session-revoke')) terminalAcceptanceFail('session revoke audit does not follow the write or lacks this-run identifiers');
    $chainFive = $examplePlan['chains']['oauth-cas-api-governance'];
    $chainFiveCleanup = $chainFive['cleanup']['steps'] ?? [];
    $chainFiveCleanupAction = $chainFiveCleanup[0] ?? [];
    $chainFiveStatus = $chainFiveCleanup[1] ?? [];
    if (($chainFive['cleanup']['api_available'] ?? null) !== true
        || isset($chainFive['manual_sql_cleanup'])
        || (($chainFiveCleanupAction['path'] ?? null) !== '/app/sand-iam/admin/acceptance-fixture/cleanup')
        || (($chainFiveCleanupAction['body']['chain_id'] ?? null) !== 'oauth-cas-api-governance')
        || (($chainFiveCleanupAction['body']['application_id'] ?? null) !== '__REQUIRED_APPLICATION_ID__')
        || (($chainFiveCleanupAction['body']['environment_id'] ?? null) !== '__REQUIRED_ENVIRONMENT_ID__')
        || (($chainFiveCleanupAction['body']['resource_id'] ?? null) !== '__REQUIRED_RESOURCE_ID__')
        || (($chainFiveCleanupAction['body']['identity_id'] ?? null) !== '__REQUIRED_APPLICATION_IDENTITY_ID__')
        || (($chainFiveCleanupAction['body']['object_ids']['oauth_client'] ?? null) !== ['${oauth_client_db_id}'])
        || (($chainFiveCleanupAction['body']['object_ids']['cas_service'] ?? null) !== ['${cas_service_id}'])
        || (($chainFiveCleanupAction['body']['object_ids']['api_resource'] ?? null) !== ['${api_resource_id}'])
        || (($chainFiveCleanupAction['body']['object_ids']['api_route_binding'] ?? null) !== ['${route_binding_id}'])
        || (($chainFiveCleanupAction['body']['object_ids']['policy'] ?? null) !== ['${policy_id}'])
        || (($chainFiveCleanupAction['body']['object_request_ids']['policy'] ?? null) !== ['${prefix}chain5-policy'])
        || !str_starts_with((string) ($chainFiveStatus['path'] ?? ''), '/app/sand-iam/admin/acceptance-fixture/status?chain_id=oauth-cas-api-governance&request_id=${prefix}chain5-status&')
        || (($chainFiveStatus['assert']['json']['data.residual.policy_version'] ?? null) !== 0)) {
        terminalAcceptanceFail('Chain5 cleanup does not bind each controlled root and derived protocol relation to the API cleanup contract');
    }
    $humanCleanup = $humanChain['cleanup']['steps'][0] ?? [];
    $humanStatus = $humanChain['cleanup']['steps'][1] ?? [];
    $humanContracts = $examplePlan['preflight']['physical_cleanup']['evidence']['chains']['human-auth-session-mfa']['action_contracts'] ?? [];
    if (($humanChain['cleanup']['api_available'] ?? null) !== true
        || isset($humanChain['manual_sql_cleanup'])
        || !is_array($humanContracts)
        || count($humanContracts) !== 1
        || (($humanCleanup['body']['object_ids']['auth_session'] ?? null) !== ['${registration_session_id}', '${login_session_id}', '${mfa_login_session_id}'])
        || (($humanCleanup['body']['human_auth_action_request_ids']['mfa_confirm'] ?? null) !== '${prefix}chain3-totp-confirm')
        || (($humanCleanup['body']['human_auth_action_request_ids']['mfa_login_verify'] ?? null) !== '${prefix}chain3-mfa-challenge-verify')
        || (($humanCleanup['body']['human_auth_session_actions'] ?? null) !== ['identity.register', 'identity.login', 'identity.mfa_login'])
        || (($humanCleanup['body']['human_auth_action_request_ids']['session_revoke'] ?? null) !== ['${prefix}chain3-registration-session-revoke', '${prefix}chain3-login-session-revoke', '${prefix}chain3-mfa-login-session-revoke'])
        || !str_contains((string) ($humanStatus['path'] ?? ''), 'human_auth_action_request_ids')) terminalAcceptanceFail('human auth cleanup does not bind every created session/factor and action audit to API cleanup');
    $routes = (string) file_get_contents($root . '/plugin/sand-iam/config/route.php');
    foreach (['/api/sand-iam/v1/authorization/decide', '/api/sand-iam/v1/oauth/authorize', '/api/sand-iam/v1/oauth/interaction/session', '/api/sand-iam/v1/oauth/interaction/confirm', '/api/sand-iam/v1/oauth/token', '/api/sand-iam/v1/cas/login', '/api/sand-iam/v1/cas/interaction/confirm', '/api/sand-iam/v1/cas/serviceValidate', '/webhook/delivery/retry'] as $route) {
        if (!str_contains($routes, $route)) terminalAcceptanceFail("live plan's critical protocol route is absent from route contract: {$route}");
    }
    $oauthController = (string) file_get_contents($root . '/plugin/sand-iam/app/api/controller/OAuthOidcController.php');
    $oauthService = (string) file_get_contents($root . '/plugin/sand-iam/app/service/OAuthOidcService.php');
    $requestIdService = (string) file_get_contents($root . '/plugin/sand-iam/app/service/RequestId.php');
    $oauthClientController = (string) file_get_contents($root . '/plugin/sand-iam/app/admin/controller/OAuthClientController.php');
    $groupService = (string) file_get_contents($root . '/plugin/sand-iam/app/service/IdentityGroupService.php');
    $groupRoleService = (string) file_get_contents($root . '/plugin/sand-iam/app/service/IdentityGroupRoleService.php');
    $policyAuthorizer = (string) file_get_contents($root . '/plugin/sand-iam/app/runtime/PolicyAuthorizer.php');
    $humanAuthService = (string) file_get_contents($root . '/plugin/sand-iam/app/service/HumanAuthService.php');
    $casController = (string) file_get_contents($root . '/plugin/sand-iam/app/api/controller/CasController.php');
    $webhookController = (string) file_get_contents($root . '/plugin/sand-iam/app/admin/controller/WebhookController.php');
    $auditController = (string) file_get_contents($root . '/plugin/sand-iam/app/admin/controller/AuditLogController.php');
    foreach (['response(\'\', 302', 'oauthError', 'SAND_IAM_OAUTH_MULTIPLE_CLIENT_AUTH_METHODS'] as $contract) if (!str_contains($oauthController, $contract)) terminalAcceptanceFail("OAuth controller contract changed: {$contract}");
    foreach (["(string) (\$payload['csrf_token'] ?? '')", "(\$payload['decision'] ?? '') !== 'approve'", "return ['csrf_token' => \$csrf", "'code_challenge_method' => 'S256'", "in_array('openid', \$scopes, true) && (\$nonce === ''", "'encrypted_nonce' => in_array('openid', \$scopes, true)", "'nonce' => \$nonce ?? ''"] as $contract) if (!str_contains($oauthService, $contract)) terminalAcceptanceFail("OAuth service interaction/nonce contract changed: {$contract}");
    foreach (["approveAuthorization(\$request->post(), \$this->bearer(\$request), \$this->requestId(\$request))", "\$this->auditClient(\$client, 'oauth.authorize_consent', 'succeeded', \$requestId, [])", "'oauth_client', (int) \$client->id, \$outcome, \$requestId !== ''"] as $contract) if (!str_contains($oauthController . $oauthService, $contract)) terminalAcceptanceFail("OAuth approval audit contract changed: {$contract}");
    foreach (["\$request->header('X-Request-Id', '')", 'RequestId::fromRequestCached($request)'] as $contract) if (!str_contains($requestIdService . $oauthController, $contract)) terminalAcceptanceFail("OAuth approval request ID is not sourced from X-Request-Id: {$contract}");
    $oauthCreate = array_values(array_filter($examplePlan['chains']['oauth-cas-api-governance']['steps'], static fn (array $step): bool => ($step['id'] ?? '') === 'create OAuth client with distinct public code and database ID'))[0] ?? [];
    $containsValue = static function (mixed $value, string $expected) use (&$containsValue): bool {
        if ($value === $expected) return true;
        if (!is_array($value)) return false;
        foreach ($value as $item) if ($containsValue($item, $expected)) return true;
        return false;
    };
    $oauthChain = $examplePlan['chains']['oauth-cas-api-governance'];
    $oauthCodeIsUsed = false;
    foreach ($oauthChain['steps'] as $step) {
        if (str_contains((string) ($step['path'] ?? ''), 'client_id=${oauth_client_code}')
            || (($step['body']['client_id'] ?? null) === '${oauth_client_code}')) {
            $oauthCodeIsUsed = true;
            break;
        }
    }
    $oauthDatabaseIdIsUsed = $containsValue($oauthChain['cleanup']['steps'] ?? [], '${oauth_client_db_id}')
        && $containsValue($oauthChain['steps'], '${oauth_client_db_id}');
    if (($oauthCreate['capture']['oauth_client_db_id']['path'] ?? null) !== 'data.id'
        || ($oauthCreate['capture']['oauth_client_code']['path'] ?? null) !== 'data.client_id'
        || !$oauthCodeIsUsed
        || !$oauthDatabaseIdIsUsed
        || $containsValue($oauthChain, '__REQUIRED_OAUTH_CLIENT_CODE__')
        || $containsValue($oauthChain, '__REQUIRED_OAUTH_CLIENT_DB_ID__')) terminalAcceptanceFail('OAuth public code and database ID are mixed or not captured from create response');
    $oauthSteps = $examplePlan['chains']['oauth-cas-api-governance']['steps'];
    $oauthAuthorizeSteps = array_values(array_filter($oauthSteps, static fn (array $step): bool => str_starts_with((string) ($step['id'] ?? ''), 'OAuth authorize')));
    if (count($oauthAuthorizeSteps) !== 2) terminalAcceptanceFail('OAuth plan must retain two independent authorize requests');
    $oauthNonces = [];
    foreach ($oauthAuthorizeSteps as $step) {
        $path = (string) ($step['path'] ?? '');
        if (!str_contains($path, 'client_id=${oauth_client_code}') || !preg_match('/[?&]nonce=([^&]+)/', $path, $matches)) terminalAcceptanceFail('OAuth authorize request does not use the public client code and an explicit nonce');
        $oauthNonces[] = $matches[1];
    }
    if (count(array_unique($oauthNonces)) !== 2) terminalAcceptanceFail('OAuth authorize requests must use distinct nonce values');
    $oauthProtocolSteps = array_values(array_filter($oauthSteps, static fn (array $step): bool => str_starts_with((string) ($step['path'] ?? ''), '/api/sand-iam/v1/oauth/')));
    if (str_contains((string) json_encode($oauthProtocolSteps, JSON_UNESCAPED_SLASHES), '${oauth_client_db_id}')) terminalAcceptanceFail('OAuth protocol request incorrectly uses the database client ID');
    $oauthConfirm = array_values(array_filter($oauthSteps, static fn (array $step): bool => ($step['id'] ?? '') === 'OAuth confirm'))[0] ?? [];
    $oauthAudit = array_values(array_filter($oauthSteps, static fn (array $step): bool => ($step['id'] ?? '') === 'audit this-run OAuth approval'))[0] ?? [];
    $oauthApprovalRequestId = '${prefix}oauth-consent-approve';
    $oauthAuditContains = $oauthAudit['assert']['json_contains']['data.data']['contains'] ?? [];
    if (($oauthConfirm['request_id'] ?? null) !== $oauthApprovalRequestId || ($oauthAudit['path'] ?? null) !== '/app/sand-iam/admin/audit/index?action=oauth.authorize_consent&outcome=succeeded&resource_type=oauth_client&request_id=' . $oauthApprovalRequestId || str_contains((string) ($oauthAudit['path'] ?? ''), 'resource_id=') || $oauthAuditContains !== ['action' => 'oauth.authorize_consent', 'outcome' => 'succeeded', 'resource_type' => 'oauth_client', 'resource_id' => '${oauth_client_db_id}', 'request_id' => $oauthApprovalRequestId]) terminalAcceptanceFail('OAuth approval audit must use the confirm X-Request-Id and assert one complete matching record');
    foreach (["['id' => (int) \$client->id, 'client_id' => (string) \$client->code]", "\$data['client_secret'] = \$secret"] as $contract) if (!str_contains($oauthClientController, $contract)) terminalAcceptanceFail("OAuth client create response contract changed: {$contract}");
    foreach (['identity_group.member_add', 'identity_group_role.grant', "'authorize.' . \$operation", 'identity.session_revoke'] as $contract) if (!str_contains($groupService . $groupRoleService . $policyAuthorizer . $humanAuthService, $contract)) terminalAcceptanceFail("live audit action is not backed by source: {$contract}");
    foreach (['application/xml; charset=utf-8', 'xmlResponse'] as $contract) if (!str_contains($casController, $contract)) terminalAcceptanceFail("CAS controller contract changed: {$contract}");
    foreach (["return \$this->success('投递任务已重新排队')", "'status' => (int)"] as $contract) if (!str_contains($webhookController, $contract)) terminalAcceptanceFail("Webhook controller contract changed: {$contract}");
    foreach (['actor_type', 'actor_ref', 'outcome', 'action', 'resource_type', 'request_id'] as $field) if (!str_contains($auditController, "'{$field}'")) terminalAcceptanceFail("Audit controller filter missing: {$field}");
    if (!str_contains($auditController, "input('resource_id'") || !str_contains($auditController, "where('resource_id', (int) \$resourceIdInput)")) terminalAcceptanceFail('Audit controller does not apply the documented exact resource_id filter');
    $delegationSteps = $examplePlan['chains']['delegation-scope']['steps'];
    $delegationCreate = array_values(array_filter($delegationSteps, static fn (array $step): bool => ($step['id'] ?? '') === 'create delegation'))[0] ?? [];
    $delegationAudit = array_values(array_filter($delegationSteps, static fn (array $step): bool => ($step['id'] ?? '') === 'audit this-run delegation creation'))[0] ?? [];
    if (($delegationCreate['request_id'] ?? null) !== '${prefix}delegation-create' || !str_contains((string) ($delegationAudit['path'] ?? ''), 'action=admin_application_grant.create') || !str_contains((string) ($delegationAudit['path'] ?? ''), 'resource_id=${delegation_id}') || !str_contains((string) ($delegationAudit['path'] ?? ''), 'request_id=${prefix}delegation-create') || (($delegationAudit['assert']['json_contains']['data.data']['contains']['request_id'] ?? null) !== '${prefix}delegation-create')) terminalAcceptanceFail('Delegation audit is not bound to the real create action, captured grant and this-run request ID');
    $workloadChain = $examplePlan['chains']['workload-credential-invocation'];
    $workloadSteps = $workloadChain['steps'];
    $workloadCleanup = $workloadChain['cleanup']['steps'] ?? [];
    $grantCreate = array_values(array_filter($workloadSteps, static fn (array $step): bool => ($step['id'] ?? '') === 'create service grant'))[0] ?? [];
    $credentialIssue = array_values(array_filter($workloadSteps, static fn (array $step): bool => ($step['id'] ?? '') === 'issue credential once'))[0] ?? [];
    $grantAudit = array_values(array_filter($workloadSteps, static fn (array $step): bool => ($step['id'] ?? '') === 'audit grant creation'))[0] ?? [];
    $credentialAudit = array_values(array_filter($workloadSteps, static fn (array $step): bool => ($step['id'] ?? '') === 'audit credential issue'))[0] ?? [];
    $credentialRevoke = array_values(array_filter($workloadSteps, static fn (array $step): bool => ($step['id'] ?? '') === 'revoke credential'))[0] ?? [];
    $workloadCleanupAction = $workloadCleanup[0] ?? [];
    $workloadGrantFallback = $workloadCleanup[1] ?? [];
    $workloadStatus = $workloadCleanup[2] ?? [];
    $workloadGrantStatus = $workloadCleanup[3] ?? [];
    $providerAllow = array_values(array_filter($workloadSteps, static fn (array $step): bool => ($step['id'] ?? '') === 'provider actual allow'))[0] ?? [];
    $providerDeny = array_values(array_filter($workloadSteps, static fn (array $step): bool => ($step['id'] ?? '') === 'provider ungranted deny'))[0] ?? [];
    $providerRevoked = array_values(array_filter($workloadSteps, static fn (array $step): bool => ($step['id'] ?? '') === 'provider rejects revoked'))[0] ?? [];
    if (($workloadChain['cleanup']['api_available'] ?? null) !== true || isset($workloadChain['manual_sql_cleanup'])
        || ($grantCreate['request_id'] ?? null) !== '${prefix}chain4-grant-create'
        || ($credentialIssue['request_id'] ?? null) !== '${prefix}chain4-credential-issue'
        || (($grantCreate['body']['workload_client_id'] ?? null) !== '__REQUIRED_WORKLOAD_CLIENT_ID__')
        || (($grantCreate['body']['service_action_id'] ?? null) !== '__REQUIRED_SERVICE_ACTION_ID__')
        || (($credentialIssue['body']['name'] ?? null) !== '${prefix}credential')
        || !str_contains((string) ($grantAudit['path'] ?? ''), 'action=service_grant.create')
        || !str_contains((string) ($grantAudit['path'] ?? ''), 'request_id=${prefix}chain4-grant-create')
        || !str_contains((string) ($credentialAudit['path'] ?? ''), 'action=credential.issue')
        || !str_contains((string) ($credentialAudit['path'] ?? ''), 'request_id=${prefix}chain4-credential-issue')
        || (($credentialRevoke['request_id'] ?? null) !== '${prefix}chain4-credential-revoke')
        || (($workloadCleanupAction['proof'] ?? null) !== 'physical_cleanup')
        || (($workloadGrantFallback['proof'] ?? null) !== 'physical_cleanup')
        || (($workloadStatus['proof'] ?? null) !== 'zero_residual')
        || (($workloadGrantStatus['proof'] ?? null) !== 'zero_residual')
        || (($workloadCleanupAction['body']['environment_id'] ?? null) !== '__REQUIRED_ENVIRONMENT_ID__')
        || !str_contains((string) ($workloadCleanupAction['path'] ?? ''), '/acceptance-fixture/cleanup')
        || !str_contains((string) ($workloadStatus['path'] ?? ''), 'service_action_id=__REQUIRED_SERVICE_ACTION_ID__')
        || (($providerAllow['write'] ?? null) !== false)
        || (($providerAllow['effect'] ?? null) !== 'sandiam_invocation_operation')
        || (($providerAllow['request_id'] ?? null) !== '${prefix}chain4-allow')
        || (($providerDeny['write'] ?? null) !== false)
        || (($providerDeny['effect'] ?? null) !== 'non_persistent')
        || (($providerRevoked['write'] ?? null) !== false)
        || (($providerRevoked['effect'] ?? null) !== 'non_persistent')
        || (($workloadCleanupAction['body']['invocation_request_ids'][0] ?? null) !== '${prefix}chain4-allow')
        || (($workloadGrantFallback['run_unless_capture_ids'] ?? null) !== ['credential_id'])
        || !str_contains((string) ($workloadStatus['path'] ?? ''), 'invocation_request_ids%5B%5D=${prefix}chain4-allow')) {
        terminalAcceptanceFail('workload credential chain does not bind creation audit, exact scope, revoke and controlled cleanup');
    }
    $workloadContracts = $examplePlan['preflight']['physical_cleanup']['evidence']['chains']['workload-credential-invocation']['action_contracts'] ?? null;
    if (!is_array($workloadContracts)
        || !str_contains((string) json_encode($workloadContracts, JSON_THROW_ON_ERROR), 'service_invocation_operation')
        || !str_contains((string) json_encode($workloadContracts, JSON_THROW_ON_ERROR), 'controlled cleanup workload grant after credential issue failure')) {
        terminalAcceptanceFail('workload credential cleanup contracts do not cover invocation and partial failure recovery');
    }
    $simulatorResult = terminalAcceptanceScript($simulator, ['--self-test']);
    if ($simulatorResult['code'] !== 0 || !str_contains($simulatorResult['stdout'], 'self-test passed')) terminalAcceptanceFail('local simulator self-test failed');
    $databaseStoreResult = terminalAcceptanceScript($acceptanceFixtureDatabaseStoreTest);
    if ($databaseStoreResult['code'] !== 0 || !str_contains($databaseStoreResult['stdout'], 'database store non-PG tests passed')) terminalAcceptanceFail('database acceptance store non-PG test failed');
    foreach (['SAND_IAM_ACCEPTANCE_LIVE=1', 'SAND_IAM_ACCEPTANCE_ALLOW_DATABASE_WRITES=1', 'SAND_IAM_ACCEPTANCE_CONFIRM=I_UNDERSTAND_THIS_WRITES_TEST_FIXTURES', 'SAND_IAM_ACCEPTANCE_FIXTURE_PREFIX=sand_iam_acceptance_0123456789abcdef_', 'SAND_IAM_ACCEPTANCE_HOST_URL=https://approved-host.example', 'SAND_IAM_ACCEPTANCE_LIVE_PLAN=' . $root . '/tools/fixtures/terminal-acceptance-live-plan.example.json'] as $gate) putenv($gate);
    $missingHost = terminalAcceptanceScript($liveDriver, ['--chain=event-webhook-delivery']);
    foreach (['SAND_IAM_ACCEPTANCE_LIVE', 'SAND_IAM_ACCEPTANCE_ALLOW_DATABASE_WRITES', 'SAND_IAM_ACCEPTANCE_CONFIRM', 'SAND_IAM_ACCEPTANCE_FIXTURE_PREFIX', 'SAND_IAM_ACCEPTANCE_HOST_URL', 'SAND_IAM_ACCEPTANCE_LIVE_PLAN'] as $gate) putenv($gate);
    if ($missingHost['code'] !== 2 || !str_contains($missingHost['stderr'], '__REQUIRED_*')) terminalAcceptanceFail('live driver did not block an incomplete plan before loading credentials or making a host request');

    // Exercise the write-before-cleanup gate and cookie isolation with an
    // injected transport: no host, database, service or secret is needed.
    define('SAND_IAM_LIVE_DRIVER_LIBRARY', true);
    require_once $liveDriver;
    require_once $acceptanceFixtureStore;
    require_once $acceptanceFixtureService;
    if (SAND_IAM_ACCEPTANCE_FIXTURE_PREFIX_PATTERN !== \plugin\SandIam\app\acceptance\AcceptanceFixtureService::PREFIX_PATTERN) terminalAcceptanceFail('driver and backend fixture-prefix contracts differ');
    if (SAND_IAM_ACCEPTANCE_FIXTURE_REQUEST_ID_PATTERN !== \plugin\SandIam\app\acceptance\AcceptanceFixtureService::REQUEST_ID_PATTERN) terminalAcceptanceFail('driver and backend fixture-request-id contracts differ');
    try {
        liveValidateHumanAuthSessionMfaProtocol($humanChain);
    } catch (Throwable $exception) {
        terminalAcceptanceFail('runtime Chain3 validator rejected the fixed three-session plan: ' . $exception->getMessage());
    }
    $oneOfThreeHumanChain = $humanChain;
    $oneOfThreeHumanChain['steps'] = array_values(array_filter($humanChain['steps'], static fn (array $step): bool => ($step['id'] ?? null) !== 'new revoked session denied' && ($step['id'] ?? null) !== 'MFA verified revoked session denied'));
    try {
        liveValidateHumanAuthSessionMfaProtocol($oneOfThreeHumanChain);
        terminalAcceptanceFail('runtime Chain3 validator accepted only one of three revoke-effective session proofs');
    } catch (RuntimeException $exception) {
        if (!str_contains($exception->getMessage(), 'Chain3')) terminalAcceptanceFail('runtime Chain3 validator rejected one-of-three proof for an unrelated reason');
    }
    try {
        liveValidateOAuthCasApiGovernanceProtocol($chainFive);
    } catch (Throwable $exception) {
        terminalAcceptanceFail('runtime Chain5 validator rejected the complete OAuth/CAS/API-governance plan: ' . $exception->getMessage());
    }
    $oneOfThreeChainFive = $chainFive;
    $oneOfThreeChainFive['steps'] = array_values(array_filter($chainFive['steps'], static fn (array $step): bool => !in_array($step['id'] ?? null, ['OAuth userinfo denies revoked access token', 'CAS XML denies disabled service ticket'], true)));
    try {
        liveValidateOAuthCasApiGovernanceProtocol($oneOfThreeChainFive);
        terminalAcceptanceFail('runtime Chain5 validator accepted one of three OAuth/CAS/policy revoke-effective proofs');
    } catch (RuntimeException $exception) {
        if (!str_contains($exception->getMessage(), 'Chain5')) terminalAcceptanceFail('runtime Chain5 validator rejected one-of-three revoke proof for an unrelated reason');
    }
    $casUserinfoFake = $chainFive;
    foreach ($casUserinfoFake['steps'] as &$step) {
        if (($step['id'] ?? null) === 'CAS XML validate') {
            $step['path'] = '/api/sand-iam/v1/oauth/userinfo';
            break;
        }
    }
    unset($step);
    try {
        liveValidateOAuthCasApiGovernanceProtocol($casUserinfoFake);
        terminalAcceptanceFail('runtime Chain5 validator accepted CAS userinfo as XML ticket validation');
    } catch (RuntimeException $exception) {
        if (!str_contains($exception->getMessage(), 'Chain5')) terminalAcceptanceFail('runtime Chain5 validator rejected the CAS fake-green plan for an unrelated reason');
    }
    foreach ([
        'swapped PKCE verifier' => static function (array &$plan): void { foreach ($plan['steps'] as &$step) if (($step['id'] ?? null) === 'correct PKCE token') $step['body']['code_verifier'] = '__REQUIRED_WRONG_PKCE_VERIFIER__'; unset($step); },
        'unbound route API id' => static function (array &$plan): void { foreach ($plan['steps'] as &$step) if (($step['id'] ?? null) === 'create controlled route binding') $step['body']['api_resource_id'] = '${route_binding_id}'; unset($step); },
        'unrelated application API' => static function (array &$plan): void { foreach ($plan['steps'] as &$step) if (($step['id'] ?? null) === 'application session permits controlled API invocation') $step['path'] = '/api/sand-iam/v1/oauth/userinfo'; unset($step); },
        'policy revoke substituted' => static function (array &$plan): void { foreach ($plan['steps'] as &$step) if (($step['id'] ?? null) === 'revoke controlled identity policy') $step['path'] = '/app/sand-iam/admin/api-route-binding/disable'; unset($step); },
        'unrelated simulate resource' => static function (array &$plan): void { foreach ($plan['steps'] as &$step) if (($step['id'] ?? null) === 'simulate controlled policy allow') $step['body']['resource_code'] = 'unrelated-resource'; unset($step); },
        'unrelated simulate action' => static function (array &$plan): void { foreach ($plan['steps'] as &$step) if (($step['id'] ?? null) === 'simulate controlled policy deny after revoke') $step['body']['action'] = 'unrelated.action'; unset($step); },
        'unrelated application API' => static function (array &$plan): void { foreach ($plan['steps'] as &$step) if (($step['id'] ?? null) === 'application session denies revoked policy API invocation') $step['body']['api_code'] = 'unrelated-api'; unset($step); },
    ] as $label => $mutate) {
        $invalid = $chainFive;
        $mutate($invalid);
        try {
            liveValidateOAuthCasApiGovernanceProtocol($invalid);
            terminalAcceptanceFail("runtime Chain5 validator accepted {$label}");
        } catch (RuntimeException $exception) {
            if (!str_contains($exception->getMessage(), 'Chain5')) terminalAcceptanceFail("runtime Chain5 validator rejected {$label} for an unrelated reason");
        }
    }
    $missingResidual = $chainFive;
    unset($missingResidual['cleanup']['steps'][1]['assert']['json']['data.residual.authorization_code']);
    try {
        liveValidateOAuthCasApiGovernanceProtocol($missingResidual);
        terminalAcceptanceFail('runtime Chain5 validator accepted a cleanup/status plan missing one derived residual assertion');
    } catch (RuntimeException $exception) {
        if (!str_contains($exception->getMessage(), 'Chain5')) terminalAcceptanceFail('runtime Chain5 validator rejected incomplete residual assertions for an unrelated reason');
    }
    $missingChainFiveScope = $chainFive;
    unset($missingChainFiveScope['cleanup']['steps'][0]['body']['environment_id']);
    try {
        liveValidateOAuthCasApiGovernanceProtocol($missingChainFiveScope);
        terminalAcceptanceFail('runtime Chain5 validator accepted cleanup without its frozen environment prerequisite');
    } catch (RuntimeException $exception) {
        if (!str_contains($exception->getMessage(), 'Chain5')) terminalAcceptanceFail('runtime Chain5 validator rejected missing cleanup environment for an unrelated reason');
    }
    $pollCalls = 0;
    $succeedsOnSecond = liveRunPoll(
        static function () use (&$pollCalls): array { $pollCalls++; return ['status' => $pollCalls === 2 ? 200 : 500]; },
        static fn (array $response): array => ['ok' => $response['status'] === 200, 'detail' => 'poll test'],
        ['max_attempts' => 3, 'interval_ms' => 100],
    );
    if ($succeedsOnSecond['attempts'] !== 2 || $pollCalls !== 2 || !$succeedsOnSecond['outcome']['ok']) terminalAcceptanceFail('bounded poll did not stop at the successful Nth attempt');
    $pollCalls = 0;
    $exhaustsAtLimit = liveRunPoll(
        static function () use (&$pollCalls): array { $pollCalls++; return ['status' => 500]; },
        static fn (array $response): array => ['ok' => $response['status'] === 200, 'detail' => 'poll test'],
        ['max_attempts' => 2, 'interval_ms' => 100],
    );
    if ($exhaustsAtLimit['attempts'] !== 2 || $pollCalls !== 2 || $exhaustsAtLimit['outcome']['ok']) terminalAcceptanceFail('bounded poll did not report the exact exhausted attempt limit');
    foreach (['sand_iam_acceptance_0123456789abcdef_', 'sand_iam_acceptance_20260829a1b2c3d4_'] as $validPrefix) {
        if (preg_match(SAND_IAM_ACCEPTANCE_FIXTURE_PREFIX_PATTERN, $validPrefix) !== 1) terminalAcceptanceFail("shared fixture-prefix contract rejected {$validPrefix}");
    }
    foreach ([
        'sand_iam_acceptance_',
        'sand_iam_acceptance_' . str_repeat('a', 15) . '_',
        'sand_iam_acceptance_' . str_repeat('a', 17) . '_',
        'sand_iam_acceptance_0123456789abcdeg_',
        'sand_iam_acceptance_0123456789ABCDEF_',
        'sand_iam_acceptance_bad/path',
    ] as $invalidPrefix) {
        if (preg_match(SAND_IAM_ACCEPTANCE_FIXTURE_PREFIX_PATTERN, $invalidPrefix) === 1) terminalAcceptanceFail("shared fixture-prefix contract accepted {$invalidPrefix}");
    }
    foreach (['organization-application-environment', 'workload-credential-invocation', 'oauth-cas-api-governance', 'delegation-scope', 'event-webhook-delivery'] as $controlledChainId) {
        $controlledChain = $examplePlan['chains'][$controlledChainId];
        $declaredContracts = $examplePlan['preflight']['physical_cleanup']['evidence']['chains'][$controlledChainId]['action_contracts'] ?? null;
        if ($declaredContracts !== liveCleanupActionContracts($controlledChain)) terminalAcceptanceFail("example action contracts drifted from controlled cleanup steps: {$controlledChainId}");
    }
    $mockPlan = [
        'format' => 'sand-iam.acceptance-live-plan/v2',
        'targets' => ['sandiam' => ['kind' => 'sandiam', 'base_url' => 'http://mock.invalid', 'allow_paths' => ['/app/sand-iam/admin']]],
        'chains' => ['organization-application-environment' => [
            'required_credentials' => ['platform_admin'], 'required_fixture_gate' => 'owned', 'physical_cleanup_gate' => 'authorized',
            'steps' => [
                ['id' => 'create', 'proof' => 'create', 'target' => 'sandiam', 'auth' => 'platform_admin', 'method' => 'POST', 'write' => true, 'path' => '/app/sand-iam/admin/save', 'body' => ['name' => '${prefix}fixture'], 'expect_http' => 200, 'assert' => ['json' => ['code' => 200, 'data.id' => ['exists' => true]]], 'capture' => ['object_id' => ['from' => 'json', 'path' => 'data.id']]],
                ['id' => 'allow', 'proof' => 'allow', 'target' => 'sandiam', 'auth' => 'platform_admin', 'method' => 'GET', 'write' => false, 'path' => '/app/sand-iam/admin/allow?id=${object_id}', 'expect_http' => 200, 'assert' => ['json' => ['code' => 200]]],
                ['id' => 'deny', 'proof' => 'deny', 'target' => 'sandiam', 'auth' => 'platform_admin', 'method' => 'GET', 'write' => false, 'path' => '/app/sand-iam/admin/deny?id=${object_id}', 'expect_http' => 403, 'assert' => ['body' => ['contains' => 'denied']]],
                ['id' => 'audit', 'proof' => 'audit', 'target' => 'sandiam', 'auth' => 'platform_admin', 'method' => 'GET', 'write' => false, 'path' => '/app/sand-iam/admin/audit?id=${object_id}', 'expect_http' => 200, 'assert' => ['json' => ['code' => 200]]],
                ['id' => 'revoke', 'proof' => 'revoke_or_recover', 'target' => 'sandiam', 'auth' => 'platform_admin', 'method' => 'POST', 'write' => true, 'path' => '/app/sand-iam/admin/revoke', 'body' => ['id' => '${object_id}'], 'expect_http' => 200, 'assert' => ['json' => ['code' => 200]]],
                ['id' => 'effective', 'proof' => 'revoke_effective', 'target' => 'sandiam', 'auth' => 'platform_admin', 'method' => 'GET', 'write' => false, 'path' => '/app/sand-iam/admin/effective?id=${object_id}', 'expect_http' => 200, 'assert' => ['json' => ['code' => 200, 'data.status' => 2]]],
            ],
            'cleanup' => ['api_available' => false, 'steps' => [['id' => 'zero', 'proof' => 'zero_residual', 'target' => 'sandiam', 'auth' => 'platform_admin', 'method' => 'GET', 'write' => false, 'path' => '/app/sand-iam/admin/zero?prefix=${prefix}', 'expect_http' => 200, 'assert' => ['json' => ['code' => 200, 'data.total' => 0]]]]],
            'manual_sql_cleanup' => [['table' => 'sand_iam_environment', 'where' => ['id' => '${object_id}'], 'reason' => 'test']],
        ]],
    ];
    putenv('SAND_IAM_ACCEPTANCE_ALLOW_HTTP=1');
    $mockTargets = ['sandiam' => liveSafeTarget($mockPlan['targets']['sandiam'], 'http://mock.invalid')];
    $observedRequestId = null;
    $transportCalls = 0;
    $GLOBALS['sand_iam_live_http_transport'] = static function (array $target, array $step, string $authorization, string $requestId) use (&$observedRequestId, &$transportCalls): array {
        $transportCalls++;
        $observedRequestId = $requestId;
        return ['status' => 200, 'body' => '{"code":200}', 'json' => ['code' => 200], 'headers' => [], 'location' => ''];
    };
    $oauthConsentRequestId = 'sand_iam_acceptance_0123456789abcdef_oauth-consent-approve';
    liveHttp($mockTargets['sandiam'], ['request_id' => $oauthConsentRequestId], '', 'fallback_request_id', null);
    if ($observedRequestId !== $oauthConsentRequestId || $transportCalls !== 1) terminalAcceptanceFail('live driver did not send the declared request_id to the OAuth controller request path');
    foreach (['ordinary-request-20260830', 'sand_iam_acceptance_oauth-consent-approve'] as $invalidRequestId) {
        try {
            liveHttp($mockTargets['sandiam'], ['request_id' => $invalidRequestId], '', 'fallback_request_id', null);
            terminalAcceptanceFail('live driver accepted a safe request_id without the complete fixture prefix');
        } catch (RuntimeException $exception) {
            if (!str_contains($exception->getMessage(), '完整验收前缀')) terminalAcceptanceFail('invalid fixture request_id failed for an unrelated reason');
        }
    }
    if ($transportCalls !== 1) terminalAcceptanceFail('invalid fixture request_id reached the HTTP transport');
    $requirements = ['organization-application-environment' => ['platform_admin']];
    $calls = [];
    $GLOBALS['sand_iam_live_http_transport'] = static function (array $target, array $step) use (&$calls): array {
        $calls[] = strtoupper((string) $step['method']);
        $path = (string) $step['path'];
        $body = match (true) {
            str_contains($path, '/save') => json_encode(['code' => 200, 'data' => ['id' => 'fixture_1']]),
            str_contains($path, '/deny') => 'denied',
            str_contains($path, '/effective') => json_encode(['code' => 200, 'data' => ['status' => 2]]),
            default => json_encode(['code' => 200, 'data' => ['id' => 'fixture_1', 'total' => 0]]),
        };
        return ['status' => str_contains($path, '/deny') ? 403 : 200, 'body' => $body, 'json' => json_decode($body, true), 'headers' => [], 'location' => ''];
    };
    try {
        $blockedDetail = '';
        try {
            liveRun('organization-application-environment', $mockPlan['chains']['organization-application-environment'], $mockTargets, ['platform_admin' => 'Authorization: Bearer test-only'], TERMINAL_ACCEPTANCE_MOCK_PREFIX, $requirements);
            terminalAcceptanceFail('manual SQL cleanup plan was allowed to reach HTTP');
        } catch (RuntimeException $exception) {
            $blockedDetail = $exception->getMessage();
        }
        if ($calls !== [] || !str_contains($blockedDetail, '写入前阻断')) terminalAcceptanceFail('manual SQL cleanup gap did not fail closed before HTTP');
        $onlyZeroCheckPlan = $mockPlan;
        $onlyZeroCheckPlan['chains']['organization-application-environment']['cleanup']['api_available'] = true;
        unset($onlyZeroCheckPlan['chains']['organization-application-environment']['manual_sql_cleanup']);
        try {
            liveRun('organization-application-environment', $onlyZeroCheckPlan['chains']['organization-application-environment'], $mockTargets, ['platform_admin' => 'Authorization: Bearer test-only'], TERMINAL_ACCEPTANCE_MOCK_PREFIX, $requirements);
            terminalAcceptanceFail('only_zero_check_no_cleanup_action was allowed to reach HTTP');
        } catch (RuntimeException $exception) {
            if (!str_contains($exception->getMessage(), '清理动作')) terminalAcceptanceFail('only_zero_check_no_cleanup_action failed for an unrelated reason');
        }
        if ($calls !== []) terminalAcceptanceFail('only_zero_check_no_cleanup_action was not rejected before HTTP');

        $apiPlan = $onlyZeroCheckPlan;
        $apiPlan['chains']['organization-application-environment']['steps'][4]['capture'] = [
            'revoked_object_id' => ['from' => 'json', 'path' => 'data.id'],
        ];
        array_unshift($apiPlan['chains']['organization-application-environment']['cleanup']['steps'], [
            'id' => 'physically delete this-run object',
            'proof' => 'physical_cleanup',
            'cleanup_for' => [
                ['step_id' => 'create', 'capture_ids' => ['object_id']],
                ['step_id' => 'revoke', 'capture_ids' => ['revoked_object_id']],
            ],
            'capture_ids' => ['object_id', 'revoked_object_id'],
            'target' => 'sandiam',
            'auth' => 'platform_admin',
            'method' => 'POST',
            'write' => true,
            'path' => '/app/sand-iam/admin/destroy',
            'body' => ['ids' => ['${object_id}', '${revoked_object_id}']],
            'expect_http' => 200,
            'assert' => ['json' => ['code' => 200]],
        ]);
        $existingFixtureWritePlan = $apiPlan;
        unset($existingFixtureWritePlan['chains']['organization-application-environment']['steps'][4]['capture']);
        $existingFixtureWritePlan['chains']['organization-application-environment']['steps'][4]['fixture_capture_ids'] = ['object_id'];
        $existingFixtureWritePlan['chains']['organization-application-environment']['cleanup']['steps'][0]['cleanup_for'] = [
            ['step_id' => 'create', 'capture_ids' => ['object_id']],
            ['step_id' => 'revoke', 'capture_ids' => ['object_id']],
        ];
        $existingFixtureWritePlan['chains']['organization-application-environment']['cleanup']['steps'][0]['capture_ids'] = ['object_id'];
        $existingFixtureWritePlan['chains']['organization-application-environment']['cleanup']['steps'][0]['body'] = ['id' => '${object_id}'];
        if (liveCleanupActionContracts($existingFixtureWritePlan['chains']['organization-application-environment']) === []) terminalAcceptanceFail('write step could not bind the fixture ID produced by an earlier create step');
        $unknownFixtureReferencePlan = $existingFixtureWritePlan;
        $unknownFixtureReferencePlan['chains']['organization-application-environment']['steps'][4]['fixture_capture_ids'] = ['unknown_id'];
        try {
            liveRun('organization-application-environment', $unknownFixtureReferencePlan['chains']['organization-application-environment'], $mockTargets, ['platform_admin' => 'Authorization: Bearer test-only'], TERMINAL_ACCEPTANCE_MOCK_PREFIX, $requirements);
            terminalAcceptanceFail('write step referenced an unknown fixture capture and reached HTTP');
        } catch (RuntimeException $exception) {
            if (!str_contains($exception->getMessage(), '尚未产生')) terminalAcceptanceFail('unknown fixture capture reference failed for an unrelated reason');
        }
        if ($calls !== []) terminalAcceptanceFail('unknown fixture capture reference was not rejected before HTTP');
        $partiallyCoveredPlan = $apiPlan;
        $partiallyCoveredPlan['chains']['organization-application-environment']['cleanup']['steps'][0]['cleanup_for'] = [
            ['step_id' => 'create', 'capture_ids' => ['object_id']],
        ];
        $partiallyCoveredPlan['chains']['organization-application-environment']['cleanup']['steps'][0]['capture_ids'] = ['object_id'];
        $partiallyCoveredPlan['chains']['organization-application-environment']['cleanup']['steps'][0]['body'] = ['id' => '${object_id}'];
        try {
            liveRun('organization-application-environment', $partiallyCoveredPlan['chains']['organization-application-environment'], $mockTargets, ['platform_admin' => 'Authorization: Bearer test-only'], TERMINAL_ACCEPTANCE_MOCK_PREFIX, $requirements);
            terminalAcceptanceFail('a physical cleanup action missing one write-step binding was allowed to reach HTTP');
        } catch (RuntimeException $exception) {
            if (!str_contains($exception->getMessage(), '逐一覆盖')) terminalAcceptanceFail('partial write-step cleanup coverage failed for an unrelated reason');
        }
        if ($calls !== []) terminalAcceptanceFail('partial write-step cleanup coverage was not rejected before HTTP');

        $wrongCapturePlan = $apiPlan;
        $wrongCapturePlan['chains']['organization-application-environment']['steps'][0]['id'] = 'create-a';
        $wrongCapturePlan['chains']['organization-application-environment']['steps'][0]['capture'] = [
            'a_id' => ['from' => 'json', 'path' => 'data.id'],
        ];
        array_splice($wrongCapturePlan['chains']['organization-application-environment']['steps'], 1, 0, [[
            'id' => 'create-b',
            'proof' => 'create',
            'target' => 'sandiam',
            'auth' => 'platform_admin',
            'method' => 'POST',
            'write' => true,
            'path' => '/app/sand-iam/admin/save',
            'body' => ['name' => '${prefix}fixture-b'],
            'expect_http' => 200,
            'assert' => ['json' => ['code' => 200, 'data.id' => ['exists' => true]]],
            'capture' => ['b_id' => ['from' => 'json', 'path' => 'data.id']],
        ]]);
        $wrongCapturePlan['chains']['organization-application-environment']['cleanup']['steps'][0]['cleanup_for'] = [
            ['step_id' => 'create-a', 'capture_ids' => ['a_id']],
            ['step_id' => 'create-b', 'capture_ids' => ['a_id']],
            ['step_id' => 'revoke', 'capture_ids' => ['revoked_object_id']],
        ];
        $wrongCapturePlan['chains']['organization-application-environment']['cleanup']['steps'][0]['capture_ids'] = ['a_id', 'revoked_object_id'];
        $wrongCapturePlan['chains']['organization-application-environment']['cleanup']['steps'][0]['body'] = ['ids' => ['${a_id}', '${revoked_object_id}']];
        try {
            liveRun('organization-application-environment', $wrongCapturePlan['chains']['organization-application-environment'], $mockTargets, ['platform_admin' => 'Authorization: Bearer test-only'], TERMINAL_ACCEPTANCE_MOCK_PREFIX, $requirements);
            terminalAcceptanceFail('create-a/create-b cleanup reused a_id for b_id and reached HTTP');
        } catch (RuntimeException $exception) {
            if (!str_contains($exception->getMessage(), 'create-b 未产生')) terminalAcceptanceFail('create-a/create-b wrong-capture binding failed for an unrelated reason');
        }
        if ($calls !== []) terminalAcceptanceFail('create-a/create-b wrong-capture binding was not rejected before HTTP');

        $duplicateCapturePlan = $apiPlan;
        $duplicateCapturePlan['chains']['organization-application-environment']['steps'][0]['id'] = 'create-a';
        array_splice($duplicateCapturePlan['chains']['organization-application-environment']['steps'], 1, 0, [[
            'id' => 'create-b',
            'proof' => 'create',
            'target' => 'sandiam',
            'auth' => 'platform_admin',
            'method' => 'POST',
            'write' => true,
            'path' => '/app/sand-iam/admin/save',
            'body' => ['name' => '${prefix}fixture-b'],
            'expect_http' => 200,
            'assert' => ['json' => ['code' => 200, 'data.id' => ['exists' => true]]],
            'capture' => ['object_id' => ['from' => 'json', 'path' => 'data.id']],
        ]]);
        $duplicateCapturePlan['chains']['organization-application-environment']['cleanup']['steps'][0]['cleanup_for'] = [
            ['step_id' => 'create-a', 'capture_ids' => ['object_id']],
            ['step_id' => 'create-b', 'capture_ids' => ['object_id']],
            ['step_id' => 'revoke', 'capture_ids' => ['revoked_object_id']],
        ];
        try {
            liveRun('organization-application-environment', $duplicateCapturePlan['chains']['organization-application-environment'], $mockTargets, ['platform_admin' => 'Authorization: Bearer test-only'], TERMINAL_ACCEPTANCE_MOCK_PREFIX, $requirements);
            terminalAcceptanceFail('create-a/create-b duplicate object_id capture names reached HTTP');
        } catch (RuntimeException $exception) {
            if (!str_contains($exception->getMessage(), '重复声明捕获名称 object_id')) terminalAcceptanceFail('duplicate write capture names failed for an unrelated reason');
        }
        if ($calls !== []) terminalAcceptanceFail('duplicate write capture names were not rejected before HTTP');

        $postGetDuplicateCapturePlan = $apiPlan;
        array_splice($postGetDuplicateCapturePlan['chains']['organization-application-environment']['steps'], 1, 0, [[
            'id' => 'read-created-object-again',
            'proof' => 'allow',
            'target' => 'sandiam',
            'auth' => 'platform_admin',
            'method' => 'GET',
            'write' => false,
            'path' => '/app/sand-iam/admin/allow?id=${object_id}',
            'expect_http' => 200,
            'assert' => ['json' => ['code' => 200, 'data.id' => ['exists' => true]]],
            'capture' => ['object_id' => ['from' => 'json', 'path' => 'data.id']],
        ]]);
        try {
            liveRun('organization-application-environment', $postGetDuplicateCapturePlan['chains']['organization-application-environment'], $mockTargets, ['platform_admin' => 'Authorization: Bearer test-only'], TERMINAL_ACCEPTANCE_MOCK_PREFIX, $requirements);
            terminalAcceptanceFail('POST+GET duplicate object_id capture names reached HTTP');
        } catch (RuntimeException $exception) {
            if (!str_contains($exception->getMessage(), '重复声明捕获名称 object_id')) terminalAcceptanceFail('POST+GET duplicate capture names failed for an unrelated reason');
        }
        if ($calls !== []) terminalAcceptanceFail('POST+GET duplicate capture names were not rejected before HTTP');

        $cleanupCaptureOverwritePlan = $apiPlan;
        $cleanupCaptureOverwritePlan['chains']['organization-application-environment']['cleanup']['steps'][0]['capture'] = [
            'object_id' => ['from' => 'json', 'path' => 'data.id'],
        ];
        try {
            liveRun('organization-application-environment', $cleanupCaptureOverwritePlan['chains']['organization-application-environment'], $mockTargets, ['platform_admin' => 'Authorization: Bearer test-only'], TERMINAL_ACCEPTANCE_MOCK_PREFIX, $requirements);
            terminalAcceptanceFail('cleanup response capture overwrote object_id and reached HTTP');
        } catch (RuntimeException $exception) {
            if (!str_contains($exception->getMessage(), 'cleanup step 不得声明 capture')) terminalAcceptanceFail('cleanup capture overwrite failed for an unrelated reason');
        }
        if ($calls !== []) terminalAcceptanceFail('cleanup response capture overwrite was not rejected before HTTP');

        $partialSameStepFixturePlan = $apiPlan;
        $partialSameStepFixturePlan['chains']['organization-application-environment']['steps'][0]['capture']['a_id'] = ['from' => 'json', 'path' => 'data.id'];
        $partialSameStepFixturePlan['chains']['organization-application-environment']['steps'][0]['capture']['b_id'] = ['from' => 'json', 'path' => 'data.id'];
        $partialSameStepFixturePlan['chains']['organization-application-environment']['cleanup']['steps'][0]['cleanup_for'][0]['capture_ids'] = ['object_id', 'a_id'];
        $partialSameStepFixturePlan['chains']['organization-application-environment']['cleanup']['steps'][0]['capture_ids'] = ['object_id', 'a_id', 'revoked_object_id'];
        $partialSameStepFixturePlan['chains']['organization-application-environment']['cleanup']['steps'][0]['body'] = ['ids' => ['${object_id}', '${a_id}', '${revoked_object_id}']];
        try {
            liveRun('organization-application-environment', $partialSameStepFixturePlan['chains']['organization-application-environment'], $mockTargets, ['platform_admin' => 'Authorization: Bearer test-only'], TERMINAL_ACCEPTANCE_MOCK_PREFIX, $requirements);
            terminalAcceptanceFail('one write step captured a_id/b_id equivalents but cleanup used only one and reached HTTP');
        } catch (RuntimeException $exception) {
            if (!str_contains($exception->getMessage(), '夹具对象 ID 未被清理动作完整覆盖')) terminalAcceptanceFail('partial same-step fixture cleanup failed for an unrelated reason');
        }
        if ($calls !== []) terminalAcceptanceFail('partial same-step fixture cleanup was not rejected before HTTP');

        $completeSameStepFixturePlan = $partialSameStepFixturePlan;
        $completeSameStepFixturePlan['chains']['organization-application-environment']['cleanup']['steps'][0]['cleanup_for'][0]['capture_ids'] = ['object_id', 'a_id', 'b_id'];
        $completeSameStepFixturePlan['chains']['organization-application-environment']['cleanup']['steps'][0]['capture_ids'] = ['object_id', 'a_id', 'b_id', 'revoked_object_id'];
        $completeSameStepFixturePlan['chains']['organization-application-environment']['cleanup']['steps'][0]['body'] = ['ids' => ['${object_id}', '${a_id}', '${b_id}', '${revoked_object_id}']];
        if ((liveRun('organization-application-environment', $completeSameStepFixturePlan['chains']['organization-application-environment'], $mockTargets, ['platform_admin' => 'Authorization: Bearer test-only'], TERMINAL_ACCEPTANCE_MOCK_PREFIX, $requirements)['status'] ?? null) !== 'passed') {
            terminalAcceptanceFail('one cleanup action using every same-step fixture capture did not pass');
        }
        if ($calls === []) terminalAcceptanceFail('fully covered same-step fixture cleanup did not exercise the injected transport');
        $calls = [];
        $cookieJarsByAuthorization = [];
        $GLOBALS['sand_iam_live_http_transport'] = static function (array $target, array $step, string $authorization, string $requestId, ?string $cookieJar) use (&$cookieJarsByAuthorization): array {
            if ($cookieJar === null) terminalAcceptanceFail('SandIAM request did not receive an isolated cookie jar');
            $cookieJarsByAuthorization[$authorization][] = $cookieJar;
            $body = str_contains((string) $step['path'], '/effective') ? ['code' => 200, 'data' => ['status' => 2]] : ['code' => 200, 'data' => ['id' => 'fixture_1', 'total' => 0]];
            if (str_contains((string) $step['path'], '/deny')) return ['status' => 403, 'body' => 'denied', 'json' => null, 'headers' => [], 'location' => ''];
            return ['status' => 200, 'body' => json_encode($body), 'json' => $body, 'headers' => [], 'location' => ''];
        };
        $apiPlan['chains']['organization-application-environment']['required_credentials'] = ['platform_admin', 'scoped_admin'];
        $apiPlan['chains']['organization-application-environment']['steps'][1]['auth'] = 'scoped_admin';
        $cookieRequirements = ['organization-application-environment' => ['platform_admin', 'scoped_admin']];
        $cookieAuthorizations = ['platform_admin' => 'Authorization: Bearer platform-test', 'scoped_admin' => 'Authorization: Bearer scoped-test'];
        if ((liveRun('organization-application-environment', $apiPlan['chains']['organization-application-environment'], $mockTargets, $cookieAuthorizations, TERMINAL_ACCEPTANCE_MOCK_PREFIX, $cookieRequirements)['status'] ?? null) !== 'passed') terminalAcceptanceFail('API cleanup chain did not pass in one stage');
        $platformJars = array_values(array_unique($cookieJarsByAuthorization[$cookieAuthorizations['platform_admin']] ?? []));
        $scopedJars = array_values(array_unique($cookieJarsByAuthorization[$cookieAuthorizations['scoped_admin']] ?? []));
        if (count($platformJars) !== 1 || count($scopedJars) !== 1 || $platformJars[0] === $scopedJars[0]) terminalAcceptanceFail('SandIAM cookie jars are not isolated by target and credential identity');
        $sharedScopePlan = $apiPlan;
        $sharedScopePlan['chains']['organization-application-environment']['steps'][0]['session_scope'] = 'scope:shared';
        $sharedScopePlan['chains']['organization-application-environment']['steps'][1]['session_scope'] = 'scope:shared';
        $callsBeforeSharedScope = array_sum(array_map('count', $cookieJarsByAuthorization));
        try {
            liveRun('organization-application-environment', $sharedScopePlan['chains']['organization-application-environment'], $mockTargets, $cookieAuthorizations, TERMINAL_ACCEPTANCE_MOCK_PREFIX, $cookieRequirements);
            terminalAcceptanceFail('two credential identities were allowed to request one custom cookie scope');
        } catch (RuntimeException $exception) {
            if (!str_contains($exception->getMessage(), 'session_scope 不允许由计划自定义')) terminalAcceptanceFail('custom shared cookie scope failed for an unrelated reason');
        }
        if (array_sum(array_map('count', $cookieJarsByAuthorization)) !== $callsBeforeSharedScope) terminalAcceptanceFail('custom shared cookie scope reached HTTP before rejection');

        $chainFourCalls = [];
        $chainFourTargets = $examplePlan['targets'];
        $chainFourTargets['sandiam'] = liveSafeTarget($chainFourTargets['sandiam'], 'http://mock.invalid');
        $GLOBALS['sand_iam_live_http_transport'] = static function (array $target, array $step) use (&$chainFourCalls): array {
            $chainFourCalls[] = (string) $step['id'];
            return match ((string) $step['id']) {
                'create service grant' => ['status' => 200, 'body' => '{"code":200,"data":{"id":99}}', 'json' => ['code' => 200, 'data' => ['id' => 99]], 'headers' => [], 'location' => ''],
                'issue credential once' => ['status' => 500, 'body' => '{"code":500}', 'json' => ['code' => 500], 'headers' => [], 'location' => ''],
                'controlled cleanup workload grant after credential issue failure', 'zero residual workload grant after credential issue failure' => ['status' => 200, 'body' => '{"code":200,"data":{"residual":{"service_grant":0,"service_quota_bucket":0}}}', 'json' => ['code' => 200, 'data' => ['residual' => ['service_grant' => 0, 'service_quota_bucket' => 0]]], 'headers' => [], 'location' => ''],
                default => terminalAcceptanceFail('partial chain4 cleanup made an unexpected HTTP request: ' . (string) $step['id']),
            };
        };
        $chainFourPartial = liveRun('workload-credential-invocation', $workloadChain, $chainFourTargets, ['platform_admin' => 'Authorization: Bearer platform-test', 'service_client' => 'Authorization: Bearer service-test'], TERMINAL_ACCEPTANCE_MOCK_PREFIX, ['workload-credential-invocation' => ['platform_admin', 'service_client']]);
        if (($chainFourPartial['cleanup']['ok'] ?? false) !== true || !in_array('controlled cleanup workload grant after credential issue failure', $chainFourCalls, true) || !in_array('zero residual workload grant after credential issue failure', $chainFourCalls, true) || in_array('controlled cleanup workload credential invocation', $chainFourCalls, true)) {
            terminalAcceptanceFail('partial chain4 failure did not select the grant-only recovery and zero-residual branch');
        }
        $preflightPlan = ['preflight' => [
            'fixture_ownership' => ['prefix' => TERMINAL_ACCEPTANCE_MOCK_PREFIX, 'confirmation' => 'I_OWN_THIS_PREFIXED_FIXTURE_SCOPE'],
            'physical_cleanup' => ['confirmation' => 'I_HAVE_VERIFIED_AUTOMATED_CLEANUP', 'evidence' => ['chains' => []]],
        ]];
        putenv('SAND_IAM_ACCEPTANCE_FIXTURE_OWNERSHIP_CONFIRM=I_OWN_THIS_PREFIXED_FIXTURE_SCOPE');
        putenv('SAND_IAM_ACCEPTANCE_AUTOMATED_CLEANUP_CONFIRM=I_HAVE_VERIFIED_AUTOMATED_CLEANUP');
        putenv('SAND_IAM_ACCEPTANCE_OWNERSHIP_EVIDENCE=approved ' . TERMINAL_ACCEPTANCE_MOCK_PREFIX . ' scope');
        putenv('SAND_IAM_ACCEPTANCE_AUTOMATED_CLEANUP_EVIDENCE=approved ' . TERMINAL_ACCEPTANCE_MOCK_PREFIX . ' organization-application-environment cleanup');
        $cleanupSteps = $apiPlan['chains']['organization-application-environment']['cleanup']['steps'];
        $preflightPlan['preflight']['physical_cleanup']['evidence']['chains']['organization-application-environment'] = [
            'api_available' => true,
            'cleanup_step_ids' => array_column($cleanupSteps, 'id'),
            'zero_residual_step_id' => $cleanupSteps[array_key_last($cleanupSteps)]['id'],
        ];
        try {
            livePreflightGate($preflightPlan, TERMINAL_ACCEPTANCE_MOCK_PREFIX, 'organization-application-environment', $apiPlan['chains']['organization-application-environment']);
            terminalAcceptanceFail('step-ID-only automatic-cleanup evidence passed preflight');
        } catch (RuntimeException $exception) {
            if (!str_contains($exception->getMessage(), '真实清理动作')) terminalAcceptanceFail('step-ID-only automatic-cleanup evidence failed for an unrelated reason');
        }
        $preflightPlan['preflight']['physical_cleanup']['evidence']['chains']['organization-application-environment'] = [
            'api_available' => true,
            'cleanup_step_ids' => array_column($cleanupSteps, 'id'),
            'action_contracts' => liveCleanupActionContracts($apiPlan['chains']['organization-application-environment']),
            'zero_residual_step_id' => $cleanupSteps[array_key_last($cleanupSteps)]['id'],
        ];
        livePreflightGate($preflightPlan, TERMINAL_ACCEPTANCE_MOCK_PREFIX, 'organization-application-environment', $apiPlan['chains']['organization-application-environment']);
        putenv('SAND_IAM_ACCEPTANCE_AUTOMATED_CLEANUP_EVIDENCE=approved ' . TERMINAL_ACCEPTANCE_MOCK_PREFIX . ' workload-credential-invocation cleanup');
        livePreflightGate($examplePlan, TERMINAL_ACCEPTANCE_MOCK_PREFIX, 'workload-credential-invocation', $workloadChain);
        $fallbackZeroEvidencePlan = $examplePlan;
        $fallbackZeroEvidencePlan['preflight']['physical_cleanup']['evidence']['chains']['workload-credential-invocation']['zero_residual_step_id'] = 'zero residual workload grant after credential issue failure';
        try {
            livePreflightGate($fallbackZeroEvidencePlan, TERMINAL_ACCEPTANCE_MOCK_PREFIX, 'workload-credential-invocation', $workloadChain);
            terminalAcceptanceFail('chain4 preflight accepted fallback zero-residual evidence for the full-success branch');
        } catch (RuntimeException $exception) {
            if (!str_contains($exception->getMessage(), '自动清理证据')) terminalAcceptanceFail('chain4 fallback zero-residual evidence failed for an unrelated reason');
        }
        $selectedFullCleanup = array_column(liveSelectedCleanupSteps($workloadChain, liveFullSuccessVariables($workloadChain)), 'id');
        $selectedGrantOnlyCleanup = array_column(liveSelectedCleanupSteps($workloadChain, ['grant_id' => '99']), 'id');
        if (!in_array('zero residual workload credential invocation', $selectedFullCleanup, true)
            || in_array('zero residual workload grant after credential issue failure', $selectedFullCleanup, true)
            || !in_array('zero residual workload grant after credential issue failure', $selectedGrantOnlyCleanup, true)
            || in_array('zero residual workload credential invocation', $selectedGrantOnlyCleanup, true)) {
            terminalAcceptanceFail('chain4 cleanup branch selection does not bind each physical cleanup to its matching zero residual step');
        }
        $chainTwoCleanupSelections = [
            'complete' => ['identity_id' => '34', 'group_id' => '35', 'group_member_id' => '36', 'group_role_relation_id' => '37'],
            'member' => ['identity_id' => '34', 'group_id' => '35', 'group_member_id' => '36'],
            'group' => ['identity_id' => '34', 'group_id' => '35'],
            'identity' => ['identity_id' => '34'],
        ];
        foreach ($chainTwoCleanupSelections as $branch => $captures) {
            $selected = array_column(liveSelectedCleanupSteps($groupChain, $captures), 'id');
            if (count($selected) !== 2
                || !str_contains($selected[0] ?? '', $branch === 'complete' ? 'complete' : $branch)
                || !str_contains($selected[1] ?? '', $branch === 'complete' ? 'complete' : $branch)) {
                terminalAcceptanceFail("chain2 {$branch} capture set did not select exactly its cleanup and zero-residual pair");
            }
        }
        $chainTwoStatusPaths = [];
        $chainTwoRequirements = [];
        foreach ($inventory['chains'] as $id => $definition) $chainTwoRequirements[$id] = array_values($definition['live_credential_slots']);
        foreach ([
            'complete' => null,
            'member' => 'grant role to group',
            'group' => 'add this-run identity to group',
            'identity' => 'create group',
        ] as $branch => $failStep) {
            $GLOBALS['sand_iam_live_http_transport'] = static function (array $target, array $step) use (&$chainTwoStatusPaths, $branch, $failStep): array {
                if (($step['id'] ?? '') === $failStep) return ['status' => 500, 'body' => '{"code":500}', 'json' => ['code' => 500], 'headers' => [], 'location' => ''];
                if (($step['proof'] ?? '') === 'zero_residual') {
                    $path = (string) ($step['path'] ?? '');
                    parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
                    if (($query['role_id'] ?? null) !== '__REQUIRED_ROLE_ID__') terminalAcceptanceFail("chain2 {$branch} driver status request omitted role_id");
                    $chainTwoStatusPaths[$branch] = $path;
                }
                $json = ['code' => 200, 'data' => []];
                $id = match ((string) ($step['id'] ?? '')) {
                    'create identity' => 34,
                    'create group' => 35,
                    'add this-run identity to group' => 36,
                    'grant role to group' => 37,
                    default => null,
                };
                if ($id !== null) $json['data'] = ['id' => $id];
                if (($step['id'] ?? '') === 'authorization decision allows derived group role') $json['data'] = ['allowed' => true];
                if (($step['id'] ?? '') === 'authorization decision denies outside policy') $json['data'] = ['allowed' => false];
                if (isset($step['assert']['json_contains']['data.data']['contains'])) $json['data'] = ['data' => [$step['assert']['json_contains']['data.data']['contains']]];
                if (($step['proof'] ?? '') === 'physical_cleanup' || ($step['proof'] ?? '') === 'zero_residual') $json['data'] = ['residual' => ['identity' => 0, 'identity_group' => 0, 'identity_group_member' => 0, 'identity_group_role' => 0]];
                if (($target['kind'] ?? '') === 'external') $json = ['allowed' => false];
                return ['status' => 200, 'body' => json_encode($json, JSON_THROW_ON_ERROR), 'json' => $json, 'headers' => [], 'location' => ''];
            };
            liveRun('identity-group-role-policy', $groupChain, $examplePlan['targets'], ['platform_admin' => 'Authorization: Bearer platform-test', 'application_user' => 'Authorization: Bearer user-test', 'out_of_scope_admin' => 'Authorization: Bearer out-of-scope-test'], TERMINAL_ACCEPTANCE_MOCK_PREFIX, $chainTwoRequirements);
            if (!isset($chainTwoStatusPaths[$branch])) terminalAcceptanceFail("chain2 {$branch} driver did not execute its selected zero-residual request");
        }
        unset($GLOBALS['sand_iam_live_http_transport']);
        putenv('SAND_IAM_ACCEPTANCE_AUTOMATED_CLEANUP_EVIDENCE=approved ' . TERMINAL_ACCEPTANCE_MOCK_PREFIX . ' organization-application-environment cleanup');
        $templatePrefixPlan = $preflightPlan;
        $templatePrefixPlan['preflight']['fixture_ownership']['prefix'] = '${prefix}';
        livePreflightGate($templatePrefixPlan, TERMINAL_ACCEPTANCE_MOCK_PREFIX, 'organization-application-environment', $apiPlan['chains']['organization-application-environment']);
        $wrongConcretePrefixPlan = $preflightPlan;
        $wrongConcretePrefixPlan['preflight']['fixture_ownership']['prefix'] = 'sand_iam_acceptance_0123456789abcdef_';
        try {
            livePreflightGate($wrongConcretePrefixPlan, TERMINAL_ACCEPTANCE_MOCK_PREFIX, 'organization-application-environment', $apiPlan['chains']['organization-application-environment']);
            terminalAcceptanceFail('preflight accepted a concrete prefix from another run');
        } catch (RuntimeException $exception) {
            if (!str_contains($exception->getMessage(), '前缀不匹配')) terminalAcceptanceFail('wrong concrete preflight prefix failed for an unrelated reason');
        }
        $pgStep = ['id' => 'zero human auth artifacts', 'proof' => 'zero_residual', 'target_kind' => 'postgres_readonly', 'verifier' => ['kind' => 'postgres_readonly', 'check' => 'human_auth_artifacts']];
        putenv('SAND_IAM_ACCEPTANCE_DB_READONLY_VERIFY'); putenv('SAND_IAM_ACCEPTANCE_DB_READONLY_CONFIRM');
        try { livePostgresReadonlyVerify($pgStep, ['captured_ids' => ['session_id' => '11', 'mfa_factor_id' => '12']]); terminalAcceptanceFail('readonly verifier connected without explicit DB authorization'); } catch (RuntimeException) { /* expected before PDO */ }
        putenv('SAND_IAM_ACCEPTANCE_DB_READONLY_VERIFY=1'); putenv('SAND_IAM_ACCEPTANCE_DB_READONLY_CONFIRM=I_UNDERSTAND_THIS_READS_EXISTING_DATABASE');
        $GLOBALS['sand_iam_live_postgres_readonly_verifier'] = static function (string $check, array $definitions, array $captured): array {
            if ($check !== 'human_auth_artifacts' || $captured !== ['session_id' => '11', 'mfa_factor_id' => '12']) terminalAcceptanceFail('readonly verifier received unsafe state values');
            $expected = ['sand_iam_auth_refresh_token.session_id', 'sand_iam_auth_session.id', 'sand_iam_mfa_recovery_code.factor_id', 'sand_iam_mfa_factor.id'];
            if (array_map(static fn (array $item): string => $item['table'] . '.' . $item['column'], $definitions) !== $expected) terminalAcceptanceFail('readonly verifier registry drifted from fixed table/column allowlist');
            return array_fill_keys($expected, 0);
        };
        if (!livePostgresReadonlyVerify($pgStep, ['captured_ids' => ['session_id' => '11', 'mfa_factor_id' => '12']])['ok']) terminalAcceptanceFail('readonly verifier rejected zero residual mock results');
        $GLOBALS['sand_iam_live_postgres_readonly_verifier'] = static fn (): array => ['sand_iam_auth_refresh_token.session_id' => 1, 'sand_iam_auth_session.id' => 0, 'sand_iam_mfa_recovery_code.factor_id' => 0, 'sand_iam_mfa_factor.id' => 0];
        if (livePostgresReadonlyVerify($pgStep, ['captured_ids' => ['session_id' => '11', 'mfa_factor_id' => '12']])['ok']) terminalAcceptanceFail('readonly verifier accepted residual session data');
    } finally {
        unset($GLOBALS['sand_iam_live_http_transport']);
        unset($GLOBALS['sand_iam_live_postgres_readonly_verifier']);
        putenv('SAND_IAM_ACCEPTANCE_ALLOW_HTTP');
        putenv('SAND_IAM_ACCEPTANCE_DB_READONLY_VERIFY');
        putenv('SAND_IAM_ACCEPTANCE_DB_READONLY_CONFIRM');
        putenv('SAND_IAM_ACCEPTANCE_FIXTURE_OWNERSHIP_CONFIRM');
        putenv('SAND_IAM_ACCEPTANCE_AUTOMATED_CLEANUP_CONFIRM');
        putenv('SAND_IAM_ACCEPTANCE_OWNERSHIP_EVIDENCE');
        putenv('SAND_IAM_ACCEPTANCE_AUTOMATED_CLEANUP_EVIDENCE');
    }
} finally {
    @unlink($reportPath);
}

echo "terminal acceptance runner non-PG tests passed\n";
