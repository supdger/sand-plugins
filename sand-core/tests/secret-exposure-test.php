<?php

declare(strict_types=1);

/**
 * 秘密字段泄露回归测试（裸 PHP，不需要真实数据库/Webman）：
 * SystemUser / SystemMail 序列化隐藏敏感列且保留 delete_time；回收站直读剔除敏感列；
 * 操作日志、异常日志与 debug 回显对嵌套/扩展敏感键遮盖；内部合法读取路径不受 $hidden 影响。
 */

namespace support\exception {
    class BusinessException extends \Exception
    {
    }
}

namespace think\model\concern {
    trait SoftDelete
    {
    }
}

namespace support\think {
    /** 最小 think-orm Model 替身：toArray 去掉 $hidden，属性读取不受影响。 */
    class Model
    {
        protected $hidden = [];
        /** @var array<string, mixed> */
        private array $data = [];
        public function __construct(array $data = []) { $this->data = $data; }
        public function __get(string $name): mixed { return $this->data[$name] ?? null; }
        public function __set(string $name, mixed $value): void { $this->data[$name] = $value; }
        public function toArray(): array
        {
            return array_diff_key($this->data, array_flip($this->hidden));
        }
        public function getHidden(): array { return $this->hidden; }
    }

    class Db
    {
        /** @var array<int, array<string, mixed>> */
        public static array $rows = [];
        public static function query(string $sql, array $params = []): array { return [[1]]; }
        public static function table(string $table): object
        {
            return new class {
                public function whereNotNull(string $c): static { return $this; }
                public function order(string $c, string $d): static { return $this; }
                public function paginate(int|string $limit): object
                {
                    return new class {
                        public function toArray(): array
                        {
                            return ['total' => count(Db::$rows), 'per_page' => 10, 'current_page' => 1, 'last_page' => 1, 'data' => Db::$rows];
                        }
                    };
                }
            };
        }
    }
}

namespace plugin\sandadmin\basic\contracts {
    interface ModelInterface
    {
        public function getTableName(): string;
        public function getPrimaryKeyName(): string;
    }
}

namespace plugin\sandadmin\basic\think {
    class BaseLogic
    {
        public $model;
    }
}

namespace Webman\Exception {
    class ExceptionHandler
    {
        public array $logged = [];
        public $logger;
        public function __construct() { $this->logger = new class($this) { public function __construct(private ExceptionHandler $owner) {} public function error(string $message): void { $this->owner->logged[] = $message; } }; }
        protected function shouldntReport(\Throwable $e): bool { return false; }
    }
}

namespace Webman\Http {
    class Request
    {
        public function __construct(private array $all) {}
        public function all(): array { return $this->all; }
        public function input(string $name, mixed $default = null): mixed { return $this->all[$name] ?? $default; }
        public function method(): string { return 'POST'; }
        public function uri(): string { return '/core/x'; }
        public function getRealIp(): string { return '127.0.0.1'; }
    }
    class Response
    {
        public function __construct(public int $status = 200, public array $headers = [], public string $body = '') {}
    }
}

namespace {
    use plugin\sandadmin\app\event\SystemUser as OperEvent;
    use plugin\sandadmin\app\exception\Handler;
    use plugin\sandadmin\app\logic\system\DatabaseLogic;
    use plugin\sandadmin\app\model\system\SystemMail;
    use plugin\sandadmin\app\model\system\SystemUser;
    use plugin\sandadmin\utils\SensitiveData;
    use support\think\Db;
    use Webman\Http\Request;

    $core = dirname(__DIR__) . '/server/plugin/sandadmin';
    $currentRequest = null;

    function config(string $key, mixed $default = null): mixed
    {
        return match ($key) {
            'think-orm.default' => 'pgsql',
            'think-orm.connections.pgsql.type' => 'pgsql',
            'app.debug' => true,
            default => $default,
        };
    }
    function request(): ?object { return $GLOBALS['currentRequest']; }
    function getCurrentInfo(): array { return ['id' => 1]; }

    require $core . '/exception/ApiException.php';
    require $core . '/utils/SensitiveData.php';
    require $core . '/basic/think/BaseModel.php';
    require $core . '/app/model/system/SystemUser.php';
    require $core . '/app/model/system/SystemMail.php';
    require $core . '/app/logic/system/DatabaseLogic.php';
    require $core . '/app/event/SystemUser.php';
    require $core . '/app/exception/Handler.php';

    function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
        fwrite(STDOUT, "PASS {$message}\n");
    }

    const HASH = '$2y$10$hashhashhashhashhashhashhashhashhashhashhashhashhashhashh';

    // ---- 模型序列化 ----
    $user = new SystemUser(['id' => 1, 'username' => 'admin', 'password' => HASH, 'delete_time' => null, 'must_change_password' => 0]);
    $out = $user->toArray();
    check(!array_key_exists('password', $out) && !str_contains(json_encode($out), HASH), 'SystemUser toArray omits the password hash');
    check(!array_key_exists('delete_time', $out) && ($out['username'] ?? '') === 'admin' && array_key_exists('must_change_password', $out), 'SystemUser still hides delete_time and keeps ordinary columns');
    check(in_array('password', $user->getHidden(), true) && in_array('delete_time', $user->getHidden(), true), 'SystemUser $hidden keeps delete_time alongside password');
    // 分页输出：think-orm paginate()->toArray() 对每个模型调用 toArray，等价于逐行 toArray。
    $page = ['total' => 2, 'data' => array_map(static fn(array $row): array => (new SystemUser($row))->toArray(), [
        ['id' => 1, 'username' => 'a', 'password' => HASH],
        ['id' => 2, 'username' => 'b', 'password' => HASH],
    ])];
    check(!str_contains(json_encode($page), 'password') && !str_contains(json_encode($page), HASH), 'SystemUser paginated output carries no password column');
    // 合法内部读取：属性访问（登录 password_verify、改密）与赋值（改密保存）不受影响。
    check(password_verify('pw', password_hash('pw', PASSWORD_DEFAULT)) && $user->password === HASH, 'password remains readable through attribute access for login/change-password');
    $user->password = 'new-hash';
    check($user->password === 'new-hash' && !array_key_exists('password', $user->toArray()), 'password remains assignable and still hidden from output');

    $mail = new SystemMail(['id' => 1, 'email' => 'a@b.c', 'code' => '123456', 'delete_time' => null]);
    $out = $mail->toArray();
    check(!array_key_exists('code', $out) && !array_key_exists('delete_time', $out) && ($out['email'] ?? '') === 'a@b.c', 'SystemMail toArray omits the verification code and delete_time');
    check($mail->code === '123456', 'SystemMail code is still readable by attribute access');

    // ---- 敏感键/列口径 ----
    foreach (['password', 'oldPassword', 'newPassword', 'confirmPassword', 'user_password', 'secret', 'client_secret', 'access_token', 'refreshToken', 'private_key', 'apiKey', 'api-key', 'secret_hash', 'Authorization'] as $key) {
        check(SensitiveData::isSensitiveKey($key) && SensitiveData::isSensitiveColumn($key), "sensitive key recognised: {$key}");
    }
    foreach (['username', 'email', 'dept_code', 'status', 'content', 'created_by'] as $key) {
        check(!SensitiveData::isSensitiveKey($key) && !SensitiveData::isSensitiveColumn($key), "ordinary key left alone: {$key}");
    }
    check(SensitiveData::isSensitiveColumn('code') && !SensitiveData::isSensitiveKey('code'), 'bare code is a sensitive column but not a sensitive request key');

    // ---- 回收站直读 ----
    $logic = new DatabaseLogic();
    $GLOBALS['currentRequest'] = new Request([]);
    Db::$rows = [
        ['id' => 1, 'username' => 'gone', 'password' => HASH, 'secret_hash' => 'h1', 'api_key' => 'k', 'access_token' => 't', 'private_key' => 'p', 'credential_id' => 3, 'code' => '654321', 'delete_time' => '2026-01-01 00:00:00'],
    ];
    $result = $logic->recycleData('sand_system_user');
    $row = $result['data'][0] ?? [];
    $encoded = json_encode($result);
    check($row !== [] && ($row['username'] ?? '') === 'gone' && ($row['id'] ?? 0) === 1 && array_key_exists('delete_time', $row), 'recycleData keeps ordinary columns and delete_time');
    check(array_intersect(['password', 'secret_hash', 'api_key', 'access_token', 'private_key', 'credential_id', 'code'], array_keys($row)) === [], 'recycleData strips every sensitive column');
    check(!str_contains($encoded, HASH) && !str_contains($encoded, '654321') && ($result['total'] ?? null) === 1, 'recycleData output leaks no secret values and keeps pagination metadata');

    // ---- 操作日志 filterParams ----
    $event = new class extends OperEvent {
        public function run(array $params): string { return $this->filterParams($params); }
    };
    $payload = [
        'username' => 'u',
        'password' => 'zz-pass-1',
        'oldPassword' => 'zz-pass-2',
        'profile' => ['nested' => ['password' => 'zz-pass-3', 'name' => 'visible'], 'client_secret' => 'zz-sec-1'],
        'items' => [['token' => 'zz-tok-1', 'label' => 'ok']],
        'api_key' => 'zz-key-1',
    ];
    $logged = $event->run($payload);
    $decoded = json_decode($logged, true);
    foreach (['zz-pass-1', 'zz-pass-2', 'zz-pass-3', 'zz-sec-1', 'zz-tok-1', 'zz-key-1'] as $secret) {
        check(!str_contains($logged, $secret), "operation log hides secret value {$secret}");
    }
    check(($decoded['username'] ?? '') === 'u' && ($decoded['profile']['nested']['name'] ?? '') === 'visible' && ($decoded['items'][0]['label'] ?? '') === 'ok', 'operation log keeps non-sensitive nested fields');
    check(($decoded['profile']['nested']['password'] ?? '') === SensitiveData::MASK && ($decoded['items'][0]['token'] ?? '') === SensitiveData::MASK, 'operation log masks nested sensitive keys');

    // ---- 异常日志与 debug 回显 ----
    $request = new Request($payload);
    $GLOBALS['currentRequest'] = $request;
    $handler = new Handler();
    $handler->report(new RuntimeException('boom'));
    $log = $handler->logged[0] ?? '';
    foreach (['zz-pass-1', 'zz-pass-2', 'zz-pass-3', 'zz-sec-1', 'zz-tok-1', 'zz-key-1'] as $secret) {
        check($log !== '' && !str_contains($log, $secret), "exception log hides secret value {$secret}");
    }
    check(str_contains($log, '"username":"u"') && str_contains($log, 'visible'), 'exception log keeps non-sensitive request params');
    $response = $handler->render($request, new RuntimeException('boom', 500));
    $body = $response->body;
    $json = json_decode($body, true);
    foreach (['zz-pass-1', 'zz-pass-2', 'zz-pass-3', 'zz-sec-1', 'zz-tok-1', 'zz-key-1'] as $secret) {
        check(!str_contains($body, $secret), "debug request_param hides secret value {$secret}");
    }
    check(($json['request_param']['profile']['nested']['name'] ?? '') === 'visible' && ($json['request_param']['password'] ?? '') === SensitiveData::MASK, 'debug request_param masks sensitive keys and keeps the rest');

    // 深度保护：超深嵌套不会无限递归。
    $deep = ['a' => 'x'];
    for ($i = 0; $i < 40; $i++) {
        $deep = ['n' => $deep];
    }
    check(is_array(SensitiveData::maskParams($deep)), 'deeply nested params are handled without runaway recursion');

    fwrite(STDOUT, "Secret exposure: all checks passed\n");
}
