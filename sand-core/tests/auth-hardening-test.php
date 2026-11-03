<?php

declare(strict_types=1);

/**
 * 认证加固测试（裸 PHP，不需要真实数据库/Webman）：
 * JWT 密钥校验（空/占位符/过短/正常）、启动自检、CheckLogin 拒绝与强制改密拦截放行、
 * 登录与改密逻辑中的标志处理。
 */

namespace support\exception {
    class BusinessException extends \Exception
    {
    }
}

namespace Webman {
    interface MiddlewareInterface
    {
    }
}

namespace Webman\Http {
    class Request
    {
        public array $headers = [];
        public function __construct(public string $controller, public string $action) {}
        public function setHeader(string $name, mixed $value): void { $this->headers[$name] = $value; }
    }
    class Response
    {
        public function __construct(public string $body = 'ok') {}
    }
}

namespace Webman\Event {
    class Event
    {
        public static array $emitted = [];
        public static function emit(string $name, array $payload): void { self::$emitted[] = [$name, $payload]; }
    }
}

namespace Tinywan\Jwt {
    class JwtToken
    {
        public static ?array $extend = null;
        public static function getExtend(): array
        {
            return self::$extend ?? throw new \RuntimeException('no token');
        }
        public static function generateToken(array $claims): array
        {
            return ['access_token' => 'signed', 'refresh_token' => 'signed-refresh', 'claims' => $claims];
        }
    }
}

namespace plugin\sandadmin\app\cache {
    class ReflectionCache
    {
        public static function getNoNeedLogin(string $controller): array
        {
            return str_ends_with($controller, 'LoginController') ? ['captcha', 'login'] : [];
        }
    }
}

namespace plugin\sandadmin\basic\think {
    class BaseLogic
    {
        public $model;
    }
}

namespace plugin\sandadmin\app\model\system {
    final class FakeUserRow
    {
        public mixed $login_time = null;
        public mixed $login_ip = null;
        public function __construct(public int $id = 0, public string $username = '', public string $password = '', public int $status = 1, public int $must_change_password = 0) {}
        public function isEmpty(): bool { return $this->id === 0; }
        public function toArray(): array
        {
            $data = ['id' => $this->id, 'username' => $this->username, 'status' => $this->status];
            if (!($GLOBALS['missingColumn'] ?? false)) {
                $data['must_change_password'] = $this->must_change_password;
            }
            return $data;
        }
        public function save(): bool { $GLOBALS['saved'][] = clone $this; return true; }
    }

    final class FakeQuery
    {
        public function __construct(private string $column, private mixed $needle) {}
        private function row(): FakeUserRow
        {
            foreach ($GLOBALS['users'] as $row) {
                if ((string) $row->{$this->column} === (string) $this->needle) {
                    return $row;
                }
            }
            return new FakeUserRow();
        }
        public function findOrEmpty(): FakeUserRow { return $this->row(); }
        public function value(string $column): mixed
        {
            if (isset($GLOBALS['databaseError'])) {
                throw new \RuntimeException($GLOBALS['databaseError']);
            }
            return $this->row()->{$column};
        }
    }

    class SystemUser
    {
        public static function __callStatic(string $name, array $arguments): FakeQuery { return new FakeQuery($arguments[0], $arguments[1]); }
        public function __call(string $name, array $arguments): mixed
        {
            if ($name === 'findOrEmpty') {
                return (new FakeQuery('id', $arguments[0]))->findOrEmpty();
            }
            return new FakeQuery($arguments[0], $arguments[1]);
        }
    }
}

namespace {
    use plugin\sandadmin\app\bootstrap\SecurityBootstrap;
    use plugin\sandadmin\app\logic\system\SystemUserLogic;
    use plugin\sandadmin\app\middleware\CheckLogin;
    use plugin\sandadmin\app\model\system\FakeUserRow;
    use plugin\sandadmin\exception\ApiException;
    use plugin\sandadmin\utils\ForcedPasswordChange;
    use plugin\sandadmin\utils\InstallGuard;
    use plugin\sandadmin\utils\JwtSecretGuard;
    use Tinywan\Jwt\JwtToken;
    use Webman\Http\Request;
    use Webman\Http\Response;

    $root = sys_get_temp_dir() . '/sand-core-auth-hardening-' . bin2hex(random_bytes(6));
    $core = dirname(__DIR__) . '/server/plugin/sandadmin';
    $jwtConfig = ['algorithms' => 'HS256', 'access_secret_key' => str_repeat('a', 64), 'refresh_secret_key' => str_repeat('b', 64)];
    $users = [];
    $saved = [];
    $missingColumn = false;

    function base_path(): string { return $GLOBALS['root']; }
    function config(string $key, mixed $default = null): mixed
    {
        return match ($key) {
            'plugin.tinywan.jwt.app.jwt' => $GLOBALS['jwtConfig'],
            default => $default,
        };
    }
    function request(): object { return new class { public function getRealIp(): string { return '127.0.0.1'; } }; }

    require $core . '/exception/ApiException.php';
    require $core . '/utils/InstallGuard.php';
    require $core . '/utils/JwtSecretGuard.php';
    require $core . '/utils/ForcedPasswordChange.php';
    require $core . '/app/bootstrap/SecurityBootstrap.php';
    require $core . '/app/middleware/CheckLogin.php';
    require $core . '/app/logic/system/SystemUserLogic.php';

    function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
        fwrite(STDOUT, "PASS {$message}\n");
    }

    /** @return array{0: ?Throwable, 1: mixed} */
    function attempt(callable $action): array
    {
        try {
            return [null, $action()];
        } catch (Throwable $error) {
            return [$error, null];
        }
    }

    function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }

    function through(string $controller, string $action): array
    {
        $request = new Request($controller, $action);
        [$error, $response] = attempt(static fn() => (new CheckLogin())->process($request, static fn(Request $request): Response => new Response('handled')));
        return [$error, $response, $request];
    }

    const USER_CONTROLLER = 'plugin\\sandadmin\\app\\controller\\system\\SystemUserController';
    const SYSTEM_CONTROLLER = 'plugin\\sandadmin\\app\\controller\\SystemController';

    try {
        mkdir($root . '/runtime', 0777, true);

        // ---- JWT 密钥：空 / 占位符 / 过短 / 正常 ----
        $valid = $jwtConfig;
        check(JwtSecretGuard::problems($valid) === [], 'valid HS256 secrets pass');
        check(count(JwtSecretGuard::problems(['algorithms' => 'HS256', 'access_secret_key' => '', 'refresh_secret_key' => str_repeat('b', 64)])) === 1, 'empty access secret is rejected');
        check(count(JwtSecretGuard::problems(['algorithms' => 'HS256', 'access_secret_key' => str_repeat('a', 64)])) === 1, 'missing refresh secret is rejected');
        $placeholder = ['algorithms' => 'HS256', 'access_secret_key' => '__JWT_ACCESS_SECRET_KEY__', 'refresh_secret_key' => '__JWT_REFRESH_SECRET_KEY__'];
        check(count(JwtSecretGuard::problems($placeholder)) === 2, 'literal placeholders are rejected for both keys');
        check(count(JwtSecretGuard::problems(['algorithms' => 'HS256', 'access_secret_key' => str_repeat('a', 31), 'refresh_secret_key' => str_repeat('b', 32)])) === 1, '31-byte secret is too short, 32 bytes is accepted');
        check(JwtSecretGuard::problems(['algorithms' => 'HS512', 'access_secret_key' => str_repeat('a', 32), 'refresh_secret_key' => str_repeat('b', 32)]) === [], 'HS512 accepts 32-byte secrets');
        check(JwtSecretGuard::problems(null) !== [] && JwtSecretGuard::problems([]) !== [], 'missing JWT configuration is rejected');
        check(JwtSecretGuard::problems(['algorithms' => 'RS256', 'access_private_key' => '', 'access_public_key' => 'pub', 'refresh_private_key' => 'priv', 'refresh_public_key' => 'pub']) !== [], 'asymmetric algorithm with an empty key is rejected');
        check(JwtSecretGuard::problems(['algorithms' => 'RS256', 'access_private_key' => 'priv', 'access_public_key' => 'pub', 'refresh_private_key' => 'priv', 'refresh_public_key' => 'pub']) === [], 'asymmetric algorithm with keys passes');
        [$error] = attempt(static fn() => JwtSecretGuard::assertValid($placeholder));
        check($error instanceof RuntimeException && str_contains($error->getMessage(), 'random_bytes') && str_contains($error->getMessage(), JwtSecretGuard::CONFIG_FILE), 'error explains how to regenerate the secrets');
        $secretConfig = ['algorithms' => 'HS256', 'access_secret_key' => 'short-secret-value', 'refresh_secret_key' => str_repeat('b', 64)];
        [$error] = attempt(static fn() => JwtSecretGuard::assertValid($secretConfig));
        check($error instanceof RuntimeException && !str_contains($error->getMessage(), 'short-secret-value'), 'error never echoes secret values');

        // ---- 启动自检 ----
        $GLOBALS['jwtConfig'] = $placeholder;
        [$error] = attempt(static fn() => SecurityBootstrap::start());
        check($error instanceof RuntimeException && !is_file(InstallGuard::tokenPath($root)), 'bootstrap refuses to start with placeholder secrets and prepares no installer token');
        $halted = [];
        $recorder = static function (object $worker) use (&$halted): void { $halted[] = $worker; };
        [$error] = attempt(static fn() => SecurityBootstrap::start(new stdClass(), $recorder));
        check($error instanceof RuntimeException && count($halted) === 1, 'bootstrap stops the master process (no restart storm) when a worker finds unsafe secrets');
        $halted = [];
        [$error] = attempt(static fn() => SecurityBootstrap::start(null, $recorder));
        check($error instanceof RuntimeException && $halted === [], 'bootstrap in CLI context (no worker) only throws and never signals a process');
        $GLOBALS['jwtConfig'] = $valid;
        $halted = [];
        ob_start();
        [$error] = attempt(static fn() => SecurityBootstrap::start(new stdClass(), $recorder));
        ob_end_clean();
        check($error === null && $halted === [] && is_file(InstallGuard::tokenPath($root)), 'bootstrap does not halt the master when secrets are valid');
        unlink(InstallGuard::tokenPath($root));
        ob_start();
        [$error] = attempt(static fn() => SecurityBootstrap::start());
        $console = (string) ob_get_clean();
        check($error === null && is_file(InstallGuard::tokenPath($root)) && str_contains($console, (string) file_get_contents(InstallGuard::tokenPath($root))), 'bootstrap prepares and announces the installer token when not installed');
        unlink(InstallGuard::tokenPath($root));
        file_put_contents($root . '/.env', 'X=1');
        ob_start();
        [$error] = attempt(static fn() => SecurityBootstrap::start());
        ob_end_clean();
        check($error === null && !is_file(InstallGuard::tokenPath($root)), 'bootstrap creates no token once installed');
        unlink($root . '/.env');

        // ---- CheckLogin：密钥不安全时拒绝请求 ----
        JwtToken::$extend = ['id' => 1, 'plat' => 'sandadmin'];
        $users = [1 => new FakeUserRow(1, 'admin', 'x', 1, 0)];
        foreach (['empty' => ['algorithms' => 'HS256', 'access_secret_key' => '', 'refresh_secret_key' => ''], 'placeholder' => $placeholder, 'short' => ['algorithms' => 'HS256', 'access_secret_key' => 'abc', 'refresh_secret_key' => 'def']] as $name => $bad) {
            $GLOBALS['jwtConfig'] = $bad;
            [$error, , $request] = through(SYSTEM_CONTROLLER, 'userInfo');
            check($error instanceof ApiException && $error->getCode() === 400 && $request->headers === [], 'CheckLogin rejects requests when secrets are ' . $name);
        }
        $GLOBALS['jwtConfig'] = $valid;
        [$error, $response] = through(SYSTEM_CONTROLLER, 'statistics');
        check($error === null && $response instanceof Response, 'CheckLogin passes with valid secrets and no forced change');

        // ---- 强制改密：拦截与放行 ----
        $users = [7 => new FakeUserRow(7, 'op', 'x', 1, 1), 8 => new FakeUserRow(8, 'normal', 'x', 1, 0)];
        JwtToken::$extend = ['id' => 7, 'plat' => 'sandadmin'];
        foreach ([[SYSTEM_CONTROLLER, 'statistics'], [SYSTEM_CONTROLLER, 'menu'], [SYSTEM_CONTROLLER, 'dictAll'], [USER_CONTROLLER, 'updateInfo'], [USER_CONTROLLER, 'index'], ['plugin\\sandpackage\\app\\controller\\InstallController', 'index']] as [$controller, $action]) {
            [$error, , $request] = through($controller, $action);
            check($error instanceof ApiException && $error->getCode() === 400 && str_contains($error->getMessage(), '修改初始密码') && $request->headers === [], 'forced change blocks ' . substr($controller, strrpos($controller, '\\') + 1) . '::' . $action);
        }
        foreach ([[USER_CONTROLLER, 'modifyPassword'], [SYSTEM_CONTROLLER, 'userInfo'], ['\\' . USER_CONTROLLER, 'modifyPassword']] as [$controller, $action]) {
            [$error, $response] = through($controller, $action);
            check($error === null && $response instanceof Response, 'forced change allows ' . $action);
        }
        JwtToken::$extend = ['id' => 8, 'plat' => 'sandadmin'];
        [$error, $response] = through(SYSTEM_CONTROLLER, 'menu');
        check($error === null && $response instanceof Response, 'user without the flag is unaffected');
        // 登录入口本身不需要登录，不受拦截
        JwtToken::$extend = ['id' => 7, 'plat' => 'sandadmin'];
        [$error, $response] = through('plugin\\sandadmin\\app\\controller\\LoginController', 'login');
        check($error === null && $response instanceof Response, 'login endpoints stay reachable');

        // 旧库没有该列：按未置位处理；其它数据库错误不得静默放行
        $missingColumnMessage = 'SQLSTATE[42703]: Undefined column: 7 ERROR: column "must_change_password" does not exist';
        check(ForcedPasswordChange::isMissingColumnError(new RuntimeException($missingColumnMessage)) && !ForcedPasswordChange::isMissingColumnError(new RuntimeException('connection refused')), 'missing-column errors are recognised precisely');
        [$error, $response] = through(SYSTEM_CONTROLLER, 'statistics');
        check($error instanceof ApiException, 'precondition: flagged user still blocked');
        $GLOBALS['databaseError'] = $missingColumnMessage;
        [$error, $response] = through(SYSTEM_CONTROLLER, 'statistics');
        check($error === null && $response instanceof Response, 'pre-upgrade schema without the column does not lock accounts out');
        $GLOBALS['databaseError'] = 'SQLSTATE[08006] connection refused';
        [$error] = through(SYSTEM_CONTROLLER, 'statistics');
        check($error instanceof RuntimeException && !($error instanceof ApiException), 'other database errors are not swallowed');
        unset($GLOBALS['databaseError']);

        // ---- 登录与改密逻辑 ----
        $logic = new SystemUserLogic();
        $users = [
            7 => new FakeUserRow(7, 'op', password_hash('initial-password-1', PASSWORD_DEFAULT), 1, 1),
            8 => new FakeUserRow(8, 'normal', password_hash('whatever-old', PASSWORD_DEFAULT), 1, 0),
            9 => new FakeUserRow(9, 'sleeper', '!', 2, 0),
        ];
        $result = $logic->login('op', 'initial-password-1', 'pc');
        check($result['must_change_password'] === true && $result['access_token'] === 'signed', 'login reports the forced-change flag');
        $result = $logic->login('normal', 'whatever-old', 'pc');
        check($result['must_change_password'] === false, 'login reports no flag for regular users');
        [$error] = attempt(static fn() => $logic->login('sleeper', '!', 'pc'));
        check($error instanceof ApiException, 'placeholder password "!" can never log in');
        $GLOBALS['jwtConfig'] = $placeholder;
        [$error] = attempt(static fn() => $logic->login('normal', 'whatever-old', 'pc'));
        check($error instanceof ApiException && $error->getCode() === 400 && !array_key_exists('claims', (array) $error), 'login refuses to issue tokens with unsafe secrets');
        $GLOBALS['jwtConfig'] = $valid;

        foreach ([['short-1', 'at least 12'], ['initial-password-1', 'same as old']] as [$new, $why]) {
            [$error] = attempt(static fn() => $logic->modifyPassword(7, 'initial-password-1', $new));
            check($error instanceof ApiException && $error->getCode() === 400 && $users[7]->must_change_password === 1, 'forced change rejects new password: ' . $why);
        }
        $saved = [];
        $changed = $logic->modifyPassword(7, 'initial-password-1', 'a-brand-new-password');
        check($changed === true && $users[7]->must_change_password === 0 && password_verify('a-brand-new-password', $users[7]->password), 'successful forced change clears the flag and stores a new hash');
        [$error, $response] = attempt(static fn() => (function () use (&$users) { JwtToken::$extend = ['id' => 7, 'plat' => 'sandadmin']; return through(SYSTEM_CONTROLLER, 'statistics'); })());
        check($response[0] === null, 'user can use the console once the flag is cleared');
        check($logic->modifyPassword(8, 'whatever-old', 'x') === true && $users[8]->must_change_password === 0, 'regular password change keeps working without the flag');
        [$error] = attempt(static fn() => $logic->modifyPassword(7, 'wrong-old-password', 'another-new-password-1'));
        check($error instanceof ApiException && str_contains($error->getMessage(), '原密码错误'), 'wrong old password is still rejected');

        // 旧库没有该列时登录/改密仍可用
        $GLOBALS['missingColumn'] = true;
        $users[8]->password = password_hash('legacy-old', PASSWORD_DEFAULT);
        check($logic->login('normal', 'legacy-old', 'pc')['must_change_password'] === false, 'login works on a pre-upgrade schema');
        check($logic->modifyPassword(8, 'legacy-old', 'legacy-new') === true, 'password change works on a pre-upgrade schema');
        $GLOBALS['missingColumn'] = false;

        // ---- 源码级：路由与前端契约 ----
        $routes = (string) file_get_contents($core . '/config/route.php');
        check(str_contains($routes, "'modifyPassword'") && str_contains($routes, "'userInfo'"), 'allow-listed endpoints exist as routes');
        $info = (string) file_get_contents($core . '/app/controller/SystemController.php');
        check(str_contains($info, "'must_change_password'"), 'user info exposes the forced-change flag to the frontend');
        $bootstrapConfig = (string) file_get_contents($core . '/config/bootstrap.php');
        check(str_contains($bootstrapConfig, 'SecurityBootstrap::class'), 'startup guard is registered in the plugin bootstrap config');
        // 同步脚本位于上级 sand_plugins 仓库；sand-core 单独检出时没有该脚本，跳过。
        $syncScript = dirname(__DIR__, 2) . '/scripts/sync-sandadmin-demo.sh';
        if (is_file($syncScript)) {
            $demoSync = (string) file_get_contents($syncScript);
            check(preg_match('/jwt_config_state" != available \]\]; then(.*?)\nfi\n/s', $demoSync, $block) === 1 && preg_match('/\bexit [1-9]/', $block[1]) === 1, 'demo sync exits non-zero when JWT secrets are not ready');
        } else {
            fwrite(STDOUT, "SKIP demo sync script not present in this checkout\n");
        }

        fwrite(STDOUT, "Auth hardening: all checks passed\n");
    } finally {
        removeTree($root);
    }
}
