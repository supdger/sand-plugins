<?php
declare(strict_types=1);

namespace plugin\sandadmin\exception { class ApiException extends \RuntimeException {} }
namespace plugin\SandIam\app\service {
    class AuditWriter {
        public static array $events = [];
        public function write(mixed ...$values): void { self::$events[] = $values; }
    }
}
namespace plugin\SandIam\app\model {
    class Credential {
        public static int $reads = 0;
        public static function where(string $key, mixed $value): never {
            self::$reads++;
            throw new \RuntimeException('credential lookup reached');
        }
    }
}
namespace plugin\SandIam\app\runtime { function time(): int { return 2000000000; } }
namespace {
    use plugin\SandIam\app\model\Credential;
    use plugin\SandIam\app\runtime\IdentityContextProvider;
    use plugin\SandIam\app\service\AuditWriter;
    use plugin\sandadmin\exception\ApiException;

    function config(string $key, mixed $default = null): mixed {
        return $key === 'plugin.sand-iam.app.context_signing_key' ? str_repeat('fixture', 8) : $default;
    }
    require __DIR__ . '/../app/service/RequestId.php';
    require __DIR__ . '/../app/runtime/IdentityContextProvider.php';
    $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    foreach ([-1, 0, 1] as $offset) {
        $payload = ['context_id' => 'expiry-fixture', 'exp' => 2000000000 + $offset, 'audience' => 'consumer', 'service_code' => 'example', 'actions' => ['read']];
        $encoded = $encode(json_encode($payload, JSON_THROW_ON_ERROR));
        $context = $encoded . '.' . $encode(hash_hmac('sha256', $encoded, config('plugin.sand-iam.app.context_signing_key'), true));
        Credential::$reads = 0; AuditWriter::$events = [];
        try {
            (new IdentityContextProvider())->verify($context, 'consumer', 'read', 'context-expiry-test');
            throw new \RuntimeException('Verification unexpectedly returned');
        } catch (ApiException $error) {
            if ($offset > 0 || $error->getCode() !== 401 || $error->getMessage() !== 'SAND_IAM_CONTEXT_EXPIRED') throw $error;
            if (Credential::$reads !== 0 || count(AuditWriter::$events) !== 1 || AuditWriter::$events[0][7] !== 'denied') throw new \RuntimeException('Expiry rejection did not precede credential lookup and emit denied audit');
        } catch (\RuntimeException $error) {
            if ($offset <= 0 || $error->getMessage() !== 'credential lookup reached' || Credential::$reads !== 1 || AuditWriter::$events !== []) throw $error;
        }
    }
    echo "Signed identity context expiry before/at/after boundary PASS (non-PG)\n";
    foreach ([null, 'read', [], ['key' => 'read'], ['read', 'read'], ['read', []], ['read', 1], ['read', '']] as $actions) {
        $payload['actions'] = $actions;
        $encoded = $encode(json_encode($payload, JSON_THROW_ON_ERROR));
        $context = $encoded . '.' . $encode(hash_hmac('sha256', $encoded, config('plugin.sand-iam.app.context_signing_key'), true));
        Credential::$reads = 0; AuditWriter::$events = [];
        try {
            (new IdentityContextProvider())->verify($context, 'consumer', 'read', 'context-actions-test');
            throw new \RuntimeException('Malformed actions accepted');
        } catch (ApiException $error) {
            if ($error->getCode() !== 403 || $error->getMessage() !== 'SAND_IAM_SERVICE_ACTION_FORBIDDEN') throw $error;
            if (Credential::$reads !== 0 || count(AuditWriter::$events) !== 1 || AuditWriter::$events[0][7] !== 'denied') throw new \RuntimeException('Malformed actions were not rejected before credential lookup');
        }
    }
    echo "Signed identity context malformed action claims rejected PASS (non-PG)\n";
}
