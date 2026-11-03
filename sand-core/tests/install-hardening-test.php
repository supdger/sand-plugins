<?php

declare(strict_types=1);

/**
 * 安装器加固测试（裸 PHP，不需要真实 PostgreSQL）：
 * 一次性令牌、安装锁定、令牌先于数据库、初始管理员凭据、种子数据与配置模板无已知口令/默认库名。
 */

namespace support {
    class Request
    {
        public function __construct(private array $data) {}
        public function post(string $name, mixed $default = null): mixed { return $this->data[$name] ?? $default; }
    }
    class Response
    {
        public function __construct(public int|array $data, public array $headers = [], public string $body = '') {}
    }
}

namespace {
    use plugin\sandadmin\app\controller\InstallController;
    use plugin\sandadmin\utils\InstallGuard;
    use support\Request;
    use support\Response;

    $root = sys_get_temp_dir() . '/sand-core-install-hardening-' . bin2hex(random_bytes(6));
    $core = dirname(__DIR__) . '/server/plugin/sandadmin';
    $env = [];
    function base_path(): string { return $GLOBALS['root']; }
    function json(array $data, int $options = 0): Response { return new Response($data); }
    function view(string $name, array $data = []): Response { return new Response(200, [], $name); }
    function config(string $key, mixed $default = null): mixed
    {
        if ($key === 'plugin.tinywan.jwt.app.jwt') {
            return ['algorithms' => 'HS256', 'access_secret_key' => str_repeat('a', 64), 'refresh_secret_key' => str_repeat('b', 64)];
        }
        return $key === 'plugin.sandadmin.app.version' ? '0.2.7' : $default;
    }
    function env(string $key, mixed $default = null): mixed { return $GLOBALS['env'][$key] ?? $default; }

    require $core . '/basic/OpenController.php';
    require $core . '/utils/InstallGuard.php';
    require $core . '/utils/JwtSecretGuard.php';
    require $core . '/app/controller/InstallController.php';

    function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
        fwrite(STDOUT, "PASS {$message}\n");
    }

    function status(Response $response): int
    {
        return is_int($response->data) ? $response->data : (int) ($response->data['code'] ?? 0);
    }

    class StubStatement extends PDOStatement
    {
        public function __construct(private array $log, private mixed $column = null, private int $rows = 1) {}
        public function fetchColumn(int $column = 0): mixed { return $this->column; }
        public function execute(?array $params = null): bool { $GLOBALS['sqlLog'][] = ['execute', $params]; return true; }
        public function rowCount(): int { return $this->rows; }
    }

    class StubDatabase extends PDO
    {
        private bool $transaction = false;
        public bool $committed = false;
        public function __construct() {}
        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false { return new StubStatement([]); }
        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            $GLOBALS['sqlLog'][] = ['prepare', $query];
            return new StubStatement([]);
        }
        public function beginTransaction(): bool { $this->transaction = true; return true; }
        public function exec(string $statement): int|false { return 0; }
        public function commit(): bool { $this->transaction = false; $this->committed = true; return true; }
        public function inTransaction(): bool { return $this->transaction; }
        public function rollBack(): bool { $this->transaction = false; return true; }
    }

    class TestInstallController extends InstallController
    {
        public int $pdoCalls = 0;
        public int $reloads = 0;
        public ?StubDatabase $database = null;
        protected function getPdo($driver, $host, $username, $password, $port, $database = null): PDO
        {
            $this->pdoCalls++;
            return $this->database ??= new StubDatabase();
        }
        protected function installPgsqlOrmMetadata(PDO $db): void {}
        protected function reloadWorkers(): void { $this->reloads++; }
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

    function payload(array $override = []): array
    {
        return array_merge([
            'databaseType' => 'pgsql', 'host' => '127.0.0.1', 'port' => 5432,
            'database' => 'sand_unit', 'username' => 'postgres', 'password' => 'db-secret',
            'dataType' => 'pure', 'installToken' => '',
            'adminUsername' => 'root_admin', 'adminPassword' => 'correct horse battery', 'adminPasswordConfirm' => 'correct horse battery',
        ], $override);
    }

    try {
        mkdir($root . '/runtime', 0777, true);
        mkdir($root . '/config', 0777, true);
        mkdir($root . '/plugin/sandadmin/db', 0777, true);
        foreach (['sandadmin-pure', 'sandadmin-6.0', 'sand-platform-menus'] as $name) {
            file_put_contents($root . '/plugin/sandadmin/db/' . $name . '.pgsql', '-- simulated');
        }
        $sqlLog = [];

        // ---- 令牌生成 ----
        $announced = [];
        $token = InstallGuard::ensureToken($root, static function (string $token, string $path) use (&$announced): void { $announced[] = [$token, $path]; });
        check(preg_match('/^[0-9a-f]{64}$/', $token) === 1, 'token is random_bytes(32) in hex');
        check(count($announced) === 1 && $announced[0][0] === $token, 'token is announced exactly once on creation');
        check(InstallGuard::ensureToken($root, static function () use (&$announced): void { $announced[] = ['again']; }) === $token && count($announced) === 1, 'second ensureToken reuses token without announcing');
        check((fileperms(InstallGuard::tokenPath($root)) & 0777) === 0600, 'token file mode is 0600');

        // ---- 令牌缺失/错误/正确 ----
        $controller = new TestInstallController();
        foreach ([[], ['installToken' => ''], ['installToken' => str_repeat('0', 64)], ['installToken' => substr($token, 0, 63)], ['installToken' => strtoupper($token)]] as $index => $bad) {
            $response = $controller->install(new Request(payload($bad)));
            check(status($response) === 400 && str_contains((string) $response->data['message'], '安装令牌'), 'missing or wrong token rejected (case ' . $index . ')');
        }
        check($controller->pdoCalls === 0, 'no database connection is opened before the token is verified');
        check(!is_file($root . '/.env') && !is_file($root . '/runtime/install.lock'), 'rejected install writes nothing');

        // 令牌文件被删除时，空令牌也不能通过，且会重建新令牌
        unlink(InstallGuard::tokenPath($root));
        $response = $controller->install(new Request(payload(['installToken' => ''])));
        check(status($response) === 400 && $controller->pdoCalls === 0, 'empty token never matches a missing token file');
        $token = InstallGuard::ensureToken($root, static function (): void {});

        // ---- 令牌通过后才进入参数/数据库阶段 ----
        $response = $controller->install(new Request(payload(['installToken' => $token, 'adminPassword' => 'short', 'adminPasswordConfirm' => 'short'])));
        check(status($response) === 400 && str_contains((string) $response->data['message'], '至少 12 位') && $controller->pdoCalls === 0, 'admin password shorter than 12 rejected before database');
        $response = $controller->install(new Request(payload(['installToken' => $token, 'adminPasswordConfirm' => 'different value!!'])));
        check(status($response) === 400 && str_contains((string) $response->data['message'], '不一致') && $controller->pdoCalls === 0, 'admin password confirmation mismatch rejected');
        $response = $controller->install(new Request(payload(['installToken' => $token, 'adminUsername' => 'a b'])));
        check(status($response) === 400 && $controller->pdoCalls === 0, 'invalid admin username rejected');
        $response = $controller->install(new Request(payload(['installToken' => $token, 'dataType' => 'bogus'])));
        check(status($response) === 400 && $controller->pdoCalls === 0, 'unknown data type rejected');
        $response = $controller->install(new Request(payload(['installToken' => $token, 'database' => ''])));
        check(status($response) === 400 && $controller->pdoCalls === 0, 'database name is required (no silent sandadmin fallback)');

        // ---- 成功安装：随机密码、强制改密、锁定 ----
        $response = $controller->install(new Request(payload(['installToken' => $token, 'adminUsername' => '', 'adminPassword' => '', 'adminPasswordConfirm' => ''])));
        check(status($response) === 200, 'install succeeds with a valid token');
        $admin = $response->data['data']['initial_admin'];
        check($admin['username'] === 'admin' && $admin['password_generated'] === true && strlen($admin['password']) >= 20 && $admin['must_change_password'] === true, 'blank password is generated once and flagged for forced change');
        check($admin['password'] !== '123456', 'generated password is not a known default');
        $update = null;
        foreach ($sqlLog as $entry) {
            if ($entry[0] === 'execute' && is_array($entry[1]) && isset($entry[1]['username'])) { $update = $entry[1]; }
        }
        check($update !== null && password_verify($admin['password'], $update['password']) && $update['username'] === 'admin', 'installer stores password_hash of the shown password');
        check($update['must_change'] === 1, 'generated password stores must_change_password = 1');
        $statements = implode("\n", array_map(static fn(array $entry): string => $entry[0] === 'prepare' ? $entry[1] : '', $sqlLog));
        check(str_contains($statements, '"must_change_password" = :must_change') && str_contains($statements, '"status" = 2'), 'initial admin flag bound as parameter and demo placeholder accounts disabled');
        check($controller->database->committed && $controller->reloads === 1, 'transaction committed and reload requested');
        check(is_file($root . '/.env') && is_file($root . '/runtime/install.lock'), '.env and install.lock written');
        check(!is_file(InstallGuard::tokenPath($root)), 'token file removed after successful install');
        check(!str_contains((string) file_get_contents($root . '/.env'), $token), 'token never written to .env');
        foreach (['think-orm.php', 'database.php', 'cache.php', 'redis.php', 'think-cache.php'] as $file) {
            check(is_file($root . '/config/' . $file), 'generated config ' . $file);
        }

        // ---- 安装后锁定：index/install 一律 404，不进入任何安装逻辑 ----
        $locked = new TestInstallController();
        $response = $locked->install(new Request(payload(['installToken' => $token])));
        check(status($response) === 404 && $locked->pdoCalls === 0, 'install returns 404 after installation (token no longer matters)');
        $response = $locked->index();
        check(status($response) === 404 && $response->body === 'Not Found', 'index returns 404 after installation');
        check(!is_file(InstallGuard::tokenPath($root)), 'index does not recreate a token after installation');

        unlink($root . '/.env');
        check(InstallGuard::isInstalled($root) && status($locked->index()) === 404 && status($locked->install(new Request(payload()))) === 404, 'install.lock alone locks the installer');
        unlink($root . '/runtime/install.lock');
        file_put_contents($root . '/.env', 'DB_NAME = "x"');
        check(InstallGuard::isInstalled($root) && status($locked->index()) === 404 && status($locked->install(new Request(payload()))) === 404, '.env alone locks the installer');
        unlink($root . '/.env');
        check(!InstallGuard::isInstalled($root), 'fresh host is not installed');

        // ---- 未安装时首次访问页面生成令牌 ----
        $fresh = new TestInstallController();
        $response = $fresh->index();
        check(status($response) === 200 && $response->body === 'install/index', 'index renders while not installed');
        check(is_file(InstallGuard::tokenPath($root)), 'first visit creates the one-time token');

        // ---- 自行填写密码：不强制改密 ----
        $token2 = trim((string) file_get_contents(InstallGuard::tokenPath($root)));
        $sqlLog = [];
        $chosen = new TestInstallController();
        $response = $chosen->install(new Request(payload(['installToken' => $token2])));
        check(status($response) === 200, 'install succeeds with an operator-chosen password');
        $admin = $response->data['data']['initial_admin'];
        check($admin['password_generated'] === false && $admin['password'] === null && $admin['must_change_password'] === false, 'chosen password is not flagged for forced change and never echoed');
        check(!str_contains((string) $admin['notice'], '必须修改密码'), 'chosen-password notice does not mention forced change');
        $update = null;
        foreach ($sqlLog as $entry) {
            if ($entry[0] === 'execute' && is_array($entry[1]) && isset($entry[1]['must_change'])) { $update = $entry[1]; }
        }
        check($update !== null && $update['must_change'] === 0 && password_verify('correct horse battery', $update['password']), 'installer stores must_change_password = 0 for a chosen password');

        // ---- 强制改密判定边界 ----
        check(InstallGuard::requiresPasswordChange(true, 'correct horse battery') === true, 'generated password always requires change');
        check(InstallGuard::requiresPasswordChange(false, 'correct horse battery') === false, 'chosen password does not require change');
        check(InstallGuard::requiresPasswordChange(false, '123456') === true, 'chosen password matching a legacy default hash requires change');

        // ---- 锁文件覆盖场景复用：清掉本次安装产物继续后续检查（令牌已被 markInstalled 删除） ----
        unlink($root . '/.env');
        unlink($root . '/runtime/install.lock');
        check(!is_file(InstallGuard::tokenPath($root)), 'successful install removes the token file');

        // ---- 配置模板：库名/密码无回退 ----
        $generate = new class extends TestInstallController {
            public function write(): void { $this->generateConfig('pgsql'); }
        };
        $generate->write();
        foreach (['think-orm.php', 'database.php'] as $file) {
            $content = (string) file_get_contents($root . '/config/' . $file);
            check(!str_contains($content, "'sandadmin'") && !str_contains($content, '123456'), $file . ' has no default database name or weak password');
            $GLOBALS['env'] = [];
            foreach (['DB_NAME', 'DB_PASSWORD'] as $missing) {
                $GLOBALS['env'] = ['DB_NAME' => 'sand_unit', 'DB_PASSWORD' => 'pw'];
                unset($GLOBALS['env'][$missing]);
                try {
                    include $root . '/config/' . $file;
                    throw new LogicException('config loaded without ' . $missing);
                } catch (RuntimeException $error) {
                    check(str_contains($error->getMessage(), $missing), $file . ' fails clearly when ' . $missing . ' is missing');
                }
            }
            $GLOBALS['env'] = ['DB_NAME' => 'sand_unit', 'DB_PASSWORD' => 'pw'];
            $loaded = include $root . '/config/' . $file;
            $connection = $loaded['connections']['pgsql'];
            check(($connection['database'] ?? null) === 'sand_unit' && ($connection['password'] ?? null) === 'pw', $file . ' uses the configured database name and password');
        }
        $GLOBALS['env'] = ['DB_NAME' => '', 'DB_PASSWORD' => 'pw'];
        try {
            include $root . '/config/database.php';
            throw new LogicException('empty DB_NAME accepted');
        } catch (RuntimeException $error) {
            check(str_contains($error->getMessage(), 'DB_NAME'), 'empty DB_NAME is rejected');
        }

        // ---- 令牌比较走 hash_equals，源码级断言 ----
        $guardSource = (string) file_get_contents($core . '/utils/InstallGuard.php');
        check(str_contains($guardSource, 'hash_equals($expected, $given)'), 'token comparison is constant time');
        $controllerSource = (string) file_get_contents($core . '/app/controller/InstallController.php');
        $tokenAt = strpos($controllerSource, 'verifyToken');
        $connectAt = strpos($controllerSource, '$this->getPdo(');
        check($tokenAt !== false && $connectAt !== false && $tokenAt < $connectAt, 'token check precedes the first database connection in install()');

        // ---- 种子/代码无已知口令与哈希 ----
        $scanned = [
            $core . '/app/controller/InstallController.php',
            $core . '/db/sandadmin-6.0.pgsql',
            $core . '/db/sandadmin-pure.pgsql',
            $core . '/db/sand-platform-menus.pgsql',
            $core . '/db/update-0.2.7.pgsql',
            $core . '/app/view/install/index.html',
        ];
        foreach ($scanned as $file) {
            $content = (string) file_get_contents($file);
            check(!str_contains($content, '123456'), basename($file) . ' contains no 123456');
            // update-0.2.7.pgsql 有意包含旧默认口令哈希（UPDATE 等值匹配置标志），由专项断言与 git 基线校验
            if (basename($file) === 'update-0.2.7.pgsql') {
                continue;
            }
            check(preg_match('/\$2[abxy]\$\d\d\$[.\/A-Za-z0-9]{53}/', $content) !== 1, basename($file) . ' contains no bcrypt hash');
        }
        foreach (['sandadmin-6.0', 'sandadmin-pure'] as $name) {
            $sql = (string) file_get_contents($core . '/db/' . $name . '.pgsql');
            check(str_contains($sql, '"must_change_password" smallint NOT NULL DEFAULT 0'), $name . ' defines must_change_password');
            check(preg_match('/VALUES \(1, \'admin\', \'!\'/', $sql) === 1, $name . ' admin seed password is an unusable placeholder');
            preg_match_all('/^INSERT INTO "sand_system_user" VALUES \((\d+), [^\n]*$/m', $sql, $rows, PREG_SET_ORDER);
            foreach ($rows as $row) {
                if ($row[1] !== '1') {
                    check(preg_match('/, 0, 2, /', $row[0]) === 1, $name . ' seed user ' . $row[1] . ' is disabled');
                }
            }
        }
        $update = (string) file_get_contents($core . '/db/update-0.2.7.pgsql');
        check(str_contains($update, 'ADD COLUMN IF NOT EXISTS "must_change_password" smallint NOT NULL DEFAULT 0'), 'update SQL is idempotent and matches the install definition');
        check(preg_match('/UPDATE "sand_system_user"\s+SET "must_change_password" = 1\s+WHERE "password" IN \(/s', $update) === 1, 'update SQL flags accounts whose password matches a known legacy hash');
        check(preg_match('/SET\s+"password"\s*=/i', $update) !== 1, 'update SQL never rewrites the password column');
        foreach (InstallGuard::LEGACY_PASSWORD_HASHES as $hash) {
            check(str_contains($update, "'" . $hash . "'"), 'update SQL lists legacy hash ' . substr($hash, 7, 8));
        }
        check(substr_count($update, '$2y$10$') === count(InstallGuard::LEGACY_PASSWORD_HASHES), 'update SQL lists exactly the legacy hash set');
        check(!str_contains($update, '`') && stripos($update, 'AUTO_INCREMENT') === false, 'update SQL uses PostgreSQL syntax only');

        // ---- 页面包含令牌输入框与获取说明 ----
        $page = (string) file_get_contents($core . '/app/view/install/index.html');
        check(str_contains($page, 'id="installToken"') && str_contains($page, '控制台/日志') && str_contains($page, "installToken: $('#installToken').val()"), 'install page asks for the token and explains where to find it');
        check(preg_match('/id="dataTypePure" value="pure" checked/', $page) === 1 && !preg_match('/id="dataTypeDemo"[^>]*checked/', $page), 'install page defaults to pure data');
        check(!str_contains($page, '{% if installed %}') && !str_contains($page, '{{token') && !str_contains($page, 'install-token}}'), 'install page never embeds the token');

        fwrite(STDOUT, "Install hardening: all checks passed\n");
    } finally {
        removeTree($root);
    }
}
