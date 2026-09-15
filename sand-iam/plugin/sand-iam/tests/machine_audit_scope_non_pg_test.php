<?php
declare(strict_types=1);
namespace {
    require __DIR__ . '/machine_application_access_non_pg_test.php';
}
namespace plugin\SandIam\app\model {
    class Credential extends \MachineAccessTest\Model {
        public static array $rows = [];
        public static function create(array $data): object {
            $data['id'] = count(self::$rows) + 1;
            self::$rows[] = $data;
            return (object) $data;
        }
    }
    class ServiceGrant extends \MachineAccessTest\Model {
        public static array $rows = [
            ['id' => 1, 'workload_client_id' => 1, 'organization_id' => 999, 'application_id' => 999],
            ['id' => 2, 'workload_client_id' => 2],
            ['id' => 3, 'workload_client_id' => 3],
            ['id' => 4, 'workload_client_id' => 4],
            ['id' => 5, 'workload_client_id' => 999]
        ];
    }
}
namespace {
    require dirname(__DIR__) . '/app/service/CredentialIssuanceService.php';
    use plugin\SandIam\app\service\AuditWriter;
    $credential = new \plugin\SandIam\app\admin\controller\CredentialController();
    $grant = new \plugin\SandIam\app\admin\controller\ServiceGrantController();
    AuditWriter::$events = [];
    $issued = invoke($credential, 'create', 1, 'credential A', null, 'request-A', '7');
    $event = AuditWriter::$events[0];
    if (array_slice($event, 2, 2) !== [1, 11]) throw new \RuntimeException('Credential issue lacks actual organization/application scope');
    invoke($credential, 'audit', 'credential.rotate.revoke', $issued['id'], 'rotate-A', '7');
    invoke($credential, 'create', 1, 'rotated A', null, 'rotate-A', '7');
    invoke($credential, 'audit', 'credential.revoke', $issued['id'], 'revoke-A', '7');
    foreach (AuditWriter::$events as $event) {
        if (array_slice($event, 2, 2) !== [1, 11] || $event[1] !== '7') throw new \RuntimeException('Credential event scope/actor changed');
    }
    if (array_column(AuditWriter::$events, 4) !== ['credential.issue', 'credential.rotate.revoke', 'credential.issue', 'credential.revoke']) {
        throw new \RuntimeException('Credential audit events changed');
    }
    if (array_column(AuditWriter::$events, 8) !== ['request-A', 'rotate-A', 'rotate-A', 'revoke-A']) throw new \RuntimeException('Request ids changed');
    foreach ([1 => [1, 11], 2 => [1, 12], 3 => [2, 21], 4 => [null, null], 5 => [null, null]] as $id => $scope) {
        foreach (['create', 'update', 'revoke'] as $verb) {
            AuditWriter::$events = [];
            invoke($grant, 'audit', $verb, $id, new \support\Request(['organization_id' => 999, 'application_id' => 999]));
            if (array_slice(AuditWriter::$events[0], 2, 2) !== $scope) throw new \RuntimeException("Grant {$id} {$verb} audit scope incorrect");
        }
    }
    foreach ([2 => [1, 12], 3 => [2, 21], 4 => [null, null], 999 => [null, null]] as $clientId => $scope) {
        AuditWriter::$events = [];
        $created = invoke($credential, 'create', $clientId, 'offline', null, 'request', '7');
        invoke($credential, 'audit', 'credential.revoke', $created['id'], 'request', '7');
        foreach (AuditWriter::$events as $event) if (array_slice($event, 2, 2) !== $scope) throw new \RuntimeException('Credential scope misattributed');
    }
    echo "machine audit scope non-PG behavior PASS\n";
    foreach (['', '   ', str_repeat('证', 129)] as $name) {
        $before = [\plugin\SandIam\app\model\Credential::$rows, AuditWriter::$events];
        try {
            invoke($credential, 'create', 1, $name, null, 'invalid-name', '7');
            throw new \RuntimeException('Invalid credential name accepted');
        } catch (\plugin\sandadmin\exception\ApiException $error) {
            if ($error->getCode() !== 400) throw $error;
        }
        if ([\plugin\SandIam\app\model\Credential::$rows, AuditWriter::$events] !== $before) throw new \RuntimeException('Invalid name created credential or audit');
    }
    $name = str_repeat('证', 128);
    $valid = invoke($credential, 'create', 1, ' ' . $name . ' ', null, 'valid-name', '7');
    $rows = \plugin\SandIam\app\model\Credential::$rows;
    $row = $rows[count($rows) - 1];
    if ($row['name'] !== $name || !password_verify($valid['credential'], $row['secret_hash'])) throw new \RuntimeException('Credential name boundary or secret hashing changed');
    echo "Credential name rejection and Unicode boundary behavior PASS\n";
    foreach ([false, true, 0, 1, '0', [], ['expire' => 'soon'], 'tomorrow', '2030-02-30 12:00:00', '2030-01-01 24:00:00', '2030-01-01 12:00:60', '2030-01-01', '2030-01-01T00:00:00Z', "2030-01-01 00:00:00\0", '0000-01-01 00:00:00'] as $expiry) {
        $before = [\plugin\SandIam\app\model\Credential::$rows, AuditWriter::$events];
        try {
            invoke($credential, 'create', 1, 'expiry-check', $expiry, 'invalid-expiry', '7');
            throw new \RuntimeException('Invalid expiry type accepted');
        } catch (\plugin\sandadmin\exception\ApiException $error) {
            if ($error->getCode() !== 400) throw $error;
        }
        if ([\plugin\SandIam\app\model\Credential::$rows, AuditWriter::$events] !== $before) throw new \RuntimeException('Invalid expiry created credential or audit');
    }
    foreach ([null, '', '2030-01-02 03:04:05', '2028-02-29 12:00:00', '2000-01-01 00:00:00'] as $expiry) {
        $issued = invoke($credential, 'create', 1, 'expiry-valid', $expiry, 'valid-expiry', '7');
        if ($issued['expire_time'] !== ($expiry === '' ? null : $expiry)) throw new \RuntimeException('Valid expiry contract changed');
    }
    echo "Credential expiry type rejection and nullable contract behavior PASS\n";
}
