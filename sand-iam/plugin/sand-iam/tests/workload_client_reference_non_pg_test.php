<?php

declare(strict_types=1);

namespace plugin\sandadmin\exception {
    class ApiException extends \RuntimeException {}
}

namespace WorkloadReferenceFixture {
    final class Query
    {
        private array $filters = [];
        public function __construct(private readonly array $rows) {}
        public function where(string $field, mixed $value): self { $this->filters[$field] = $value; return $this; }
        public function whereNull(string $field): self { $this->filters[$field] = null; return $this; }
        public function find(): ?object
        {
            foreach ($this->rows as $row) {
                foreach ($this->filters as $field => $value) {
                    if (($row[$field] ?? null) !== $value) continue 2;
                }
                return (object) $row;
            }
            return null;
        }
    }
}

namespace plugin\SandIam\app\model {
    class WorkloadClient
    {
        public static array $rows = [];
        public static function where(string $field, mixed $value): \WorkloadReferenceFixture\Query
        { return (new \WorkloadReferenceFixture\Query(self::$rows))->where($field, $value); }
    }
    class Environment
    {
        public static array $rows = [];
        public static function where(string $field, mixed $value): \WorkloadReferenceFixture\Query
        { return (new \WorkloadReferenceFixture\Query(self::$rows))->where($field, $value); }
    }
    class Application
    {
        public static array $rows = [];
        public static function where(string $field, mixed $value): \WorkloadReferenceFixture\Query
        { return (new \WorkloadReferenceFixture\Query(self::$rows))->where($field, $value); }
    }
    class Organization
    {
        public static array $rows = [];
        public static function where(string $field, mixed $value): \WorkloadReferenceFixture\Query
        { return (new \WorkloadReferenceFixture\Query(self::$rows))->where($field, $value); }
    }
}

namespace {
    require dirname(__DIR__) . '/app/runtime/WorkloadClientReferenceVerifier.php';

    $checks = 0;
    $assert = static function (bool $condition) use (&$checks): void {
        if (!$condition) throw new \RuntimeException('Workload reference failed at check ' . ($checks + 1));
        ++$checks;
    };
    $client = \plugin\SandIam\app\model\WorkloadClient::class;
    $environment = \plugin\SandIam\app\model\Environment::class;
    $application = \plugin\SandIam\app\model\Application::class;
    $organization = \plugin\SandIam\app\model\Organization::class;
    $client::$rows = [['id' => 44, 'environment_id' => 33, 'status' => 1, 'delete_time' => null]];
    $environment::$rows = [['id' => 33, 'application_id' => 22, 'status' => 1, 'delete_time' => null]];
    $application::$rows = [['id' => 22, 'organization_id' => 11, 'status' => 1, 'delete_time' => null]];
    $organization::$rows = [['id' => 11, 'status' => 1, 'delete_time' => null]];
    $verifier = new \plugin\SandIam\app\runtime\WorkloadClientReferenceVerifier();
    $assert($verifier->verifyForService(44) === [
        'organization_id' => 11, 'application_id' => 22,
        'environment_id' => 33, 'workload_client_id' => 44, 'status' => 1,
    ]);
    $reject = static function (callable $operation) use (&$checks): void {
        try { $operation(); }
        catch (\plugin\sandadmin\exception\ApiException $error) {
            if ($error->getCode() !== 403 || !str_starts_with($error->getMessage(), 'SAND_IAM_INVOCATION_FACTS_UNVERIFIED')) {
                throw new \RuntimeException('Wrong rejection');
            }
            ++$checks;
            return;
        }
        throw new \RuntimeException('Unavailable workload reference was accepted');
    };
    $reject(static fn () => $verifier->verifyForService(0));
    $reject(static fn () => $verifier->verifyForService(45));
    foreach ([$client, $environment, $application, $organization] as $model) {
        $model::$rows[0]['status'] = 2;
        $reject(static fn () => $verifier->verifyForService(44));
        $model::$rows[0]['status'] = 1;
        $model::$rows[0]['delete_time'] = '2026-09-25 00:00:00';
        $reject(static fn () => $verifier->verifyForService(44));
        $model::$rows[0]['delete_time'] = null;
    }
    echo 'workload-client-reference ' . $checks . '/' . $checks . PHP_EOL;
}
