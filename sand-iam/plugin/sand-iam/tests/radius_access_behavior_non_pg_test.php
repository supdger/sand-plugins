<?php

declare(strict_types=1);

namespace RadiusAccessTest {
    final class State
    {
        public static array $rows = [];
        public static array $calls = [];
        public static bool $replayFailure = false;
        public static bool $passwordFailure = false;
        public static array $config = [
            'radius_server_enabled' => 1,
            'radius_replay_key' => 'offline-replay-key-at-least-32-characters',
        ];
        public const SECRET = 'offline-radius-shared-secret';
    }
    class Row
    {
        public function __construct(public array $data) {}
        public function __get(string $name): mixed { return $this->data[$name] ?? null; }
        public static function where(string $field, mixed $operator, mixed $value = null): Query
        {
            return (new Query(static::class))->where($field, $operator, $value);
        }
        public static function create(array $data): void
        {
            if (State::$replayFailure) throw new \RuntimeException('offline replay storage failure');
            foreach (State::$rows[static::class] ?? [] as $row) {
                if ($row->radius_nas_id === $data['radius_nas_id'] && $row->request_fingerprint === $data['request_fingerprint']) {
                    throw new \RuntimeException('23505 unique replay');
                }
            }
            State::$rows[static::class][] = new static($data);
        }
    }
    final class Query
    {
        private array $filters = [];
        public function __construct(private string $model) {}
        public function where(string $field, mixed $operator, mixed $value = null): self
        {
            $this->filters[] = [$field, $value === null ? '=' : $operator, $value ?? $operator];
            return $this;
        }
        private function matches(Row $row): bool
        {
            foreach ($this->filters as [$field, $operator, $value]) {
                if (!match ($operator) {
                    '=' => $row->{$field} === $value,
                    '<=' => $row->{$field} <= $value,
                    default => throw new \LogicException('Unsupported fixture query'),
                }) return false;
            }
            return true;
        }
        public function select(): self { return $this; }
        public function all(): array { return array_values(array_filter(State::$rows[$this->model] ?? [], fn (Row $row): bool => $this->matches($row))); }
        public function find(): ?Row { return $this->all()[0] ?? null; }
        public function delete(): void
        {
            State::$rows[$this->model] = array_values(array_filter(State::$rows[$this->model] ?? [], fn (Row $row): bool => !$this->matches($row)));
        }
    }
}

namespace plugin\sandadmin\exception { class ApiException extends \RuntimeException {} }
namespace plugin\SandIam\app\model {
    final class Application extends \RadiusAccessTest\Row {}
    final class RadiusNas extends \RadiusAccessTest\Row {}
    final class RadiusReplay extends \RadiusAccessTest\Row {}
}
namespace plugin\SandIam\app\service {
    final class RadiusSecretCipher
    {
        public function decrypt(string $value): string
        {
            if ($value !== 'offline-fixture') throw new \RuntimeException('Unexpected cipher fixture');
            return \RadiusAccessTest\State::SECRET;
        }
    }
    final class HumanAuthService
    {
        public function verifyPasswordForProtocol(object $application, string $username, string $password, string $ip, string $requestId, string $protocol): void
        {
            \RadiusAccessTest\State::$calls[] = [$application->id, $username, $password, $ip, $requestId, $protocol];
            if (\RadiusAccessTest\State::$passwordFailure) throw new \RuntimeException('offline password backend failure');
            if ($password !== 'correct-horse') throw new \plugin\sandadmin\exception\ApiException('SAND_IAM_INVALID_CREDENTIALS', 401);
        }
    }
}

namespace {
    use RadiusAccessTest\State;
    use plugin\SandIam\app\model\Application;
    use plugin\SandIam\app\model\RadiusNas;
    use plugin\SandIam\app\model\RadiusReplay;
    use plugin\SandIam\app\radius\RadiusPacketCodec;
    use plugin\SandIam\app\service\RadiusAccessService;

    function config(string $key, mixed $default = null): mixed { return State::$config[substr($key, strlen('plugin.sand-iam.app.'))] ?? $default; }
    function check(bool $condition, string $message): void { if (!$condition) throw new \RuntimeException($message); }
    require dirname(__DIR__) . '/app/radius/RadiusNetwork.php';
    require dirname(__DIR__) . '/app/radius/RadiusPacketCodec.php';
    require dirname(__DIR__) . '/app/service/RadiusAccessService.php';

    function requestPacket(array $usernames = ['reader'], string $password = 'correct-horse', int $code = 1): string
    {
        static $identifier = 0;
        $identifier++;
        $authenticator = pack('N', $identifier) . str_repeat('r', 12);
        $attributes = '';
        foreach ($usernames as $username) $attributes .= chr(1) . chr(strlen($username) + 2) . $username;
        $encrypted = str_pad($password, 16, "\0") ^ hash('md5', State::SECRET . $authenticator, true);
        $attributes .= chr(2) . chr(18) . $encrypted . chr(80) . chr(18) . str_repeat("\0", 16);
        $wire = pack('CCn', $code, $identifier, 20 + strlen($attributes)) . $authenticator . $attributes;
        return substr_replace($wire, hash_hmac('md5', $wire, State::SECRET, true), -16, 16);
    }
    function responseCode(?string $wire, string $request, int $expected): void
    {
        check(is_string($wire), 'Expected a signed response');
        $codec = new RadiusPacketCodec();
        $packet = $codec->decode($wire);
        check($packet['code'] === $expected && $packet['identifier'] === ord($request[1]), 'Wrong response code or identifier');
        $requestAuthenticator = substr($request, 4, 16);
        check(hash_equals(hash('md5', substr($wire, 0, 4) . $requestAuthenticator . substr($wire, 20) . State::SECRET, true), $packet['authenticator']), 'Invalid response authenticator');
        $records = array_values(array_filter($packet['attributes'], static fn (array $attribute): bool => $attribute['type'] === 80));
        check(count($records) === 1, 'Response must have one Message-Authenticator');
        $hmacInput = substr_replace($wire, $requestAuthenticator, 4, 16);
        $hmacInput = substr_replace($hmacInput, str_repeat("\0", 16), $records[0]['offset'] + 2, 16);
        check(hash_equals(hash_hmac('md5', $hmacInput, State::SECRET, true), $codec->values($packet, 80)[0]), 'Invalid response Message-Authenticator');
    }

    $nas = new RadiusNas(['id' => 1, 'application_id' => 10, 'source_cidr' => '192.0.2.0/24', 'status' => 1, 'encrypted_shared_secret' => 'offline-fixture']);
    State::$rows = [RadiusNas::class => [$nas], Application::class => [new Application(['id' => 10, 'status' => 1])]];
    $service = new RadiusAccessService();
    $ip = '192.0.2.12';
    $request = requestPacket();
    responseCode($service->handle($ip, $request), $request, 2);
    check(count(State::$calls) === 1 && array_slice(State::$calls[0], 0, 4) === [10, 'reader', 'correct-horse', $ip], 'Password verification lost application, username, decrypted password or trusted source');
    check(preg_match('/^radius_[0-9a-f]{32}$/', State::$calls[0][4]) === 1 && State::$calls[0][5] === 'radius', 'Password verification lost request/protocol context');
    $replayState = serialize(State::$rows[RadiusReplay::class]);
    check($service->handle($ip, $request) === null && count(State::$calls) === 1 && serialize(State::$rows[RadiusReplay::class]) === $replayState, 'Replay repeated authentication or changed cache state');

    $wrongPassword = requestPacket(password: 'wrong-password');
    responseCode($service->handle($ip, $wrongPassword), $wrongPassword, 3);
    check(count(State::$calls) === 2, 'Wrong password did not reach password verification exactly once');
    foreach ([[], ['reader', 'other'], [''], ["bad\0name"], ["\xff"]] as $names) {
        $invalidUser = requestPacket($names);
        responseCode($service->handle($ip, $invalidUser), $invalidUser, 3);
        check(count(State::$calls) === 2, 'Invalid username reached password verification');
    }
    $tampered = requestPacket();
    $tampered[strlen($tampered) - 1] = chr(ord($tampered[strlen($tampered) - 1]) ^ 1);
    $before = serialize(State::$rows);
    foreach ([$tampered, substr(requestPacket(), 0, -1), requestPacket(code: 2)] as $invalidPacket) {
        check($service->handle($ip, $invalidPacket) === null, 'Invalid packet was answered');
    }
    check(serialize(State::$rows) === $before && count(State::$calls) === 2, 'Invalid packet reached replay storage or password verification');
    foreach (['invalid-ip', '198.51.100.1'] as $unknownSource) check($service->handle($unknownSource, requestPacket()) === null, 'Unknown source was answered');
    State::$rows[RadiusNas::class][] = new RadiusNas($nas->data + ['unused' => true]);
    check($service->handle($ip, requestPacket()) === null, 'Overlapping NAS selection was accepted');
    array_pop(State::$rows[RadiusNas::class]);
    State::$config['radius_server_enabled'] = 0;
    check($service->handle($ip, requestPacket()) === null, 'Disabled server was answered');
    State::$config['radius_server_enabled'] = 1;
    State::$rows[Application::class][0]->data['status'] = 2;
    $inactive = requestPacket();
    responseCode($service->handle($ip, $inactive), $inactive, 3);
    check(count(State::$calls) === 2, 'Disabled application reached password verification');
    State::$rows[Application::class][0]->data['status'] = 1;

    State::$replayFailure = true;
    $retry = requestPacket();
    check($service->handle($ip, $retry) === null && count(State::$calls) === 2, 'Replay storage failure allowed authentication');
    State::$replayFailure = false;
    responseCode($service->handle($ip, $retry), $retry, 2);
    State::$passwordFailure = true;
    check($service->handle($ip, requestPacket()) === null, 'Unexpected password backend failure was accepted');
    State::$passwordFailure = false;
    $recovered = requestPacket();
    responseCode($service->handle($ip, $recovered), $recovered, 2);
    echo "RADIUS Access actual-entry non-PG behavior checks passed\n";
}
