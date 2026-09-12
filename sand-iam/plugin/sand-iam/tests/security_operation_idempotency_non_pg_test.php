<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

namespace plugin\sandadmin\exception {
    final class ApiException extends \RuntimeException {}
}

namespace think\facade {
    final class Db
    {
        public static int $starts = 0;
        public static int $commits = 0;
        public static int $rollbacks = 0;
        public static function startTrans(): void { self::$starts++; }
        public static function commit(): void { self::$commits++; }
        public static function rollback(): void { self::$rollbacks++; }
    }
}

namespace plugin\SandIam\app\model {
    final class SecurityOperationQuery
    {
        /** @var null|callable(SecurityOperation):void */
        public static $afterPendingFind = null;
        /** @var array<string,string> */
        private array $where = [];
        public function where(string $field, string $value): self { $this->where[$field] = $value; return $this; }
        public function lock(bool $lock): self { return $this; }
        public function find(): ?SecurityOperation
        {
            foreach (SecurityOperation::$rows as $row) {
                foreach ($this->where as $field => $value) {
                    if ((string) $row->{$field} !== $value) continue 2;
                }
                if ((string) $row->state === 'pending' && is_callable(self::$afterPendingFind)) {
                    $callback = self::$afterPendingFind;
                    self::$afterPendingFind = null;
                    $callback($row);
                }
                return $row;
            }
            return null;
        }
    }

    final class SecurityOperation
    {
        /** @var array<int,self> */ public static array $rows = [];
        public static int $nextId = 1;
        public int $id;
        public string $actor_type;
        public string $actor_ref;
        public string $operation;
        public string $request_id;
        public string $request_fingerprint;
        public string $resource_type;
        public ?int $resource_id = null;
        public string $state;
        /** @var array<string,mixed>|string */ public array|string $result;
        public string $create_time;
        public string $update_time;
        public static function where(string $field, string $value): SecurityOperationQuery { return (new SecurityOperationQuery())->where($field, $value); }
        /** @param array<string,mixed> $payload */
        public static function create(array $payload): self
        {
            $record = new self();
            $record->id = self::$nextId++;
            foreach ($payload as $field => $value) $record->{$field} = $value;
            self::$rows[$record->id] = $record;
            return $record;
        }
        /** @param array<string,mixed> $payload */
        public function save(array $payload): void { foreach ($payload as $field => $value) $this->{$field} = $value; }
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/service/RequestId.php';
    require_once dirname(__DIR__) . '/app/service/IdempotencyService.php';

    use plugin\SandIam\app\service\IdempotencyService;
    use plugin\SandIam\app\service\RequestId;
    use plugin\SandIam\app\model\SecurityOperation;
    use plugin\SandIam\app\model\SecurityOperationQuery;
    use plugin\sandadmin\exception\ApiException;

    function tP0(bool $condition, string $message): void
    {
        if (!$condition) { fwrite(STDERR, $message . PHP_EOL); exit(1); }
    }

    tP0(RequestId::normalize('caller-req_20260822') === 'caller-req_20260822', 'valid request id changed');
    tP0((bool) preg_match('/^req_[0-9a-f]{32}$/', RequestId::normalize('too short')), 'invalid request id was not regenerated');

    $service = new IdempotencyService();
    $fingerprint = IdempotencyService::fingerprint(['name' => 'production', 'workload_client_id' => 7]);
    $calls = 0;
    $operation = static function () use (&$calls): array {
        $calls++;
        return ['resource_id' => 42, 'result' => ['id' => 42, 'key_prefix' => 'siam_abcdef', 'credential' => 'siam_secret_once']];
    };
    $first = $service->execute('admin', '7', 'credential.issue', 'caller-req_20260822', $fingerprint, 'credential', $operation);
    tP0($first['replayed'] === false && ($first['result']['credential'] ?? null) === 'siam_secret_once', 'initial security operation did not return its one-time secret');
    $replayed = $service->execute('admin', '7', 'credential.issue', 'caller-req_20260822', $fingerprint, 'credential', $operation);
    tP0($replayed['replayed'] === true && $calls === 1 && !isset($replayed['result']['credential']) && ($replayed['result']['secret_available'] ?? true) === false, 'replayed security operation did not suppress the stored secret');
    try {
        $service->execute('admin', '7', 'credential.issue', 'caller-req_20260822', IdempotencyService::fingerprint(['name' => 'different']), 'credential', $operation);
        tP0(false, 'same request id with different input was accepted');
    } catch (ApiException $exception) {
        tP0($exception->getCode() === 409 && str_contains($exception->getMessage(), 'SAND_IAM_IDEMPOTENCY_CONFLICT'), 'idempotency conflict did not use the stable error');
    }
    $otherActor = $service->execute('admin', '8', 'credential.issue', 'caller-req_20260822', $fingerprint, 'credential', $operation);
    tP0($otherActor['replayed'] === false && $calls === 2, 'request id was incorrectly shared across security actors');

    $oauthCalls = 0;
    $oauthFingerprint = IdempotencyService::fingerprint(['id' => 77]);
    $oauthOperation = static function () use (&$oauthCalls): array {
        $oauthCalls++;
        return ['resource_id' => 77, 'result' => ['client_id' => 'controlled-client', 'client_secret' => 'siam_cs_secret_once']];
    };
    $oauthFirst = $service->execute('admin', '7', 'oauth_client.secret_rotate', 'oauth-rotate-20260912', $oauthFingerprint, 'oauth_client', $oauthOperation);
    tP0($oauthFirst['replayed'] === false && ($oauthFirst['result']['client_secret'] ?? null) === 'siam_cs_secret_once', 'initial OAuth rotation did not return its one-time client secret');
    $oauthReplay = $service->execute('admin', '7', 'oauth_client.secret_rotate', 'oauth-rotate-20260912', $oauthFingerprint, 'oauth_client', $oauthOperation);
    tP0($oauthReplay['replayed'] === true && $oauthCalls === 1 && !isset($oauthReplay['result']['client_secret']) && ($oauthReplay['result']['secret_available'] ?? true) === false, 'OAuth rotation replay exposed or regenerated the client secret');

    $sensitiveFingerprint = IdempotencyService::fingerprint(['scope' => 'recursive-redaction']);
    $sensitiveOperation = static fn (): array => [
        'resource_id' => 81,
        'result' => [
            'id' => 81,
            'recovery_codes' => ['recovery-secret'],
            'private_key' => 'private-secret',
            'otpauth_uri' => 'otpauth://totp/example?secret=totp-secret',
            'challenge_token' => 'siam_mc_challenge-secret',
            'nested' => [
                'shared_secret' => 'shared-secret',
                'authorization' => 'Bearer secret',
                'credential_id' => 82,
                'secret_version' => 'v2',
            ],
        ],
    ];
    $sensitiveFirst = $service->execute('admin', '7', 'security.material.rotate', 'security-material-20260912', $sensitiveFingerprint, 'security_material', $sensitiveOperation);
    tP0(isset($sensitiveFirst['result']['recovery_codes'], $sensitiveFirst['result']['private_key'], $sensitiveFirst['result']['nested']['shared_secret']), 'initial response lost caller-owned one-time security material');
    $sensitiveReplay = $service->execute('admin', '7', 'security.material.rotate', 'security-material-20260912', $sensitiveFingerprint, 'security_material', $sensitiveOperation);
    tP0(!isset($sensitiveReplay['result']['recovery_codes'], $sensitiveReplay['result']['private_key'], $sensitiveReplay['result']['otpauth_uri'], $sensitiveReplay['result']['challenge_token'], $sensitiveReplay['result']['nested']['shared_secret'], $sensitiveReplay['result']['nested']['authorization']), 'recursive idempotency replay exposed security material');
    tP0(($sensitiveReplay['result']['nested']['credential_id'] ?? null) === 82 && ($sensitiveReplay['result']['nested']['secret_version'] ?? null) === 'v2', 'recursive idempotency redaction removed safe resource metadata');

    $pendingFingerprint = IdempotencyService::fingerprint(['operation' => 'onboarding.apply', 'scope' => 'same-admin']);
    $pending = SecurityOperation::create([
        'actor_type' => 'admin', 'actor_ref' => '9', 'operation' => 'onboarding.apply', 'request_id' => 'onboarding-concurrent-20260823',
        'request_fingerprint' => $pendingFingerprint, 'resource_type' => 'onboarding', 'state' => 'pending', 'result' => [], 'create_time' => date('Y-m-d H:i:s'), 'update_time' => date('Y-m-d H:i:s'),
    ]);
    SecurityOperationQuery::$afterPendingFind = static function (SecurityOperation $record): void {
        $record->save(['state' => 'succeeded', 'resource_id' => 99, 'result' => ['workload_client_id' => 99]]);
    };
    $completed = $service->waitForCompletedReplay('admin', '9', 'onboarding.apply', 'onboarding-concurrent-20260823', $pendingFingerprint, 2, 1);
    tP0($completed !== null && $completed['replayed'] === true && ($completed['result']['workload_client_id'] ?? null) === 99 && ($completed['result']['secret_available'] ?? null) === false, 'matching in-flight operation did not wait for and return its completed replay');
    tP0($pending->state === 'succeeded', 'in-flight completion hook did not settle the pending record');

    $root = dirname(__DIR__, 3);
    $runtime = file_get_contents($root . '/plugin/sand-iam/app/api/controller/RuntimeContextController.php');
    $provider = file_get_contents($root . '/plugin/sand-iam/app/runtime/IdentityContextProvider.php');
    $authorization = file_get_contents($root . '/plugin/sand-iam/app/api/controller/AuthorizationController.php');
    $authorizationService = file_get_contents($root . '/plugin/sand-iam/app/runtime/ApplicationAuthorizationService.php');
    $credentials = file_get_contents($root . '/plugin/sand-iam/app/admin/controller/CredentialController.php');
    $audit = file_get_contents($root . '/plugin/sand-iam/app/service/AuditWriter.php');
    foreach ([$runtime, $provider, $authorization, $authorizationService, $credentials, $audit] as $source) tP0(is_string($source), 'request-id source is unreadable');
    tP0(str_contains($runtime, 'RequestId::fromRequest($request)') && str_contains($provider, 'verify(string $context, string $expectedAudience, string $requiredAction, string $requestId'), 'runtime context does not carry one request id through issue and verify');
    tP0(str_contains($authorization, 'RequestId::fromRequest($request)') && substr_count($authorizationService, 'RequestId::normalize($requestId)') >= 3, 'authorization decide or route does not normalize request id');
    tP0(str_contains($credentials, 'IdempotencyService::fingerprint') && str_contains($credentials, "'credential.issue'") && str_contains($credentials, "'credential.rotate'") && str_contains($credentials, "'credential.revoke'"), 'credential mutations are not idempotency guarded');
    tP0(str_contains($audit, 'RequestId::normalize($requestId)'), 'access audit accepts an unvalidated request id');
    echo 'security operation idempotency non-PG checks passed' . PHP_EOL;
}
