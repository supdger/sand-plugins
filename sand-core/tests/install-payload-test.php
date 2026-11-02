<?php

declare(strict_types=1);

use SandAdmin\Core\FrontendPublisher;
use SandAdmin\Core\Install;

/**
 * Install 的后端载荷安装与清单驱动卸载测试（裸 PHP assert）。
 *
 * 载荷源路径在 Install 内相对 __DIR__ 解析，因此测试把安装器复制到临时包里，
 * 对合成载荷与合成宿主跑真实代码。
 */

function base_path(string $path = ''): string
{
    return $GLOBALS['sand_test_base_path'] . ($path === '' ? '' : '/' . $path);
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isLink()) {
            unlink($item->getPathname());
            continue;
        }
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

function write(string $path, string $content): void
{
    $parent = dirname($path);
    if (!is_dir($parent)) {
        mkdir($parent, 0777, true);
    }
    file_put_contents($path, $content);
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$repositoryRoot = dirname(__DIR__);
$root = sys_get_temp_dir() . '/sand-core-install-' . bin2hex(random_bytes(6));
$package = $root . '/pkg';
$payloadSource = $package . '/server/plugin/sandadmin';
$host = $root . '/project/server';
$payload = $host . '/plugin/sandadmin';
$frontend = $root . '/project/sandadmin-artd';

mkdir($package . '/server', 0777, true);
foreach (['PublishExclusions.php', 'FrontendPublisher.php', 'Install.php'] as $file) {
    copy($repositoryRoot . '/server/' . $file, $package . '/server/' . $file);
}
require_once $package . '/server/PublishExclusions.php';
require_once $package . '/server/FrontendPublisher.php';
require_once $package . '/server/Install.php';

write($payloadSource . '/app/controller/DemoController.php', "<?php\n// v1\n");
write($payloadSource . '/config/app.php', "<?php\nreturn ['version' => '1'];\n");
write($payloadSource . '/db/schema.sql', "-- v1\n");
write($package . '/sandadmin-artd/package.json', "{\"name\":\"artd\"}\n");
write($package . '/sandadmin-artd/src/main.ts', "export const main = 1\n");
write($package . '/sandadmin-artd/.env.example', "VITE_API_URL = /api\n");
write($package . '/sandadmin-artd/.env.development.example', "VITE_API_URL = /api\n");

$GLOBALS['sand_test_base_path'] = $host;
mkdir($host, 0777, true);
write($host . '/composer.json', (string) json_encode([
    'extra' => ['sandadmin' => ['frontend-path' => '../sandadmin-artd']],
]));

try {
    // 1. 首装：后端载荷先行，前端随后
    Install::install();
    check(is_file($payload . '/app/controller/DemoController.php'), '后端载荷应安装');
    check(is_file($payload . '/.sand-core-payload-manifest.json'), '后端载荷应写清单');
    check(is_file($frontend . '/' . FrontendPublisher::MANIFEST), '前端应发布并生效清单');

    // 2. 升级：普通载荷覆盖，config/ 仅首次写入
    write($payloadSource . '/app/controller/DemoController.php', "<?php\n// v2\n");
    write($payloadSource . '/config/app.php', "<?php\nreturn ['version' => '2'];\n");
    write($payload . '/config/app.php', "<?php\nreturn ['version' => 'host'];\n");
    write($payload . '/app/controller/DemoController.php', "<?php\n// host 改动\n");
    write($payload . '/runtime-note.txt', "运维放置的未登记文件\n");
    Install::update();
    check(
        (string) file_get_contents($payload . '/config/app.php') === "<?php\nreturn ['version' => 'host'];\n",
        'config/ 载荷不应在升级时覆盖宿主修改'
    );
    check(
        (string) file_get_contents($payload . '/app/controller/DemoController.php') === "<?php\n// v2\n",
        '非 config 载荷应随升级覆盖'
    );

    // 3. 前端发布失败不阻塞后端载荷
    write($frontend . '/' . FrontendPublisher::MANIFEST, "{ 坏清单\n");
    write($payloadSource . '/app/controller/DemoController.php', "<?php\n// v3\n");
    Install::update();
    check(
        (string) file_get_contents($payload . '/app/controller/DemoController.php') === "<?php\n// v3\n",
        '前端发布失败不应回滚或跳过后端载荷'
    );
    unlink($frontend . '/' . FrontendPublisher::MANIFEST);
    Install::update();
    check(is_file($frontend . '/' . FrontendPublisher::MANIFEST), '清理坏清单后前端应重新收养发布');

    // 4. 卸载：登记且未修改的文件删除，未登记文件与宿主修改保留
    Install::uninstall();
    check(is_file($payload . '/runtime-note.txt'), '未登记文件应保留');
    check(is_file($payload . '/config/app.php'), '宿主修改过的 config 应保留');
    check(!is_file($payload . '/app/controller/DemoController.php'), '登记且未修改的载荷文件应删除');
    check(!is_file($payload . '/db/schema.sql'), '登记且未修改的载荷文件应删除');
    check(!is_file($payload . '/.sand-core-payload-manifest.json'), '卸载应移除载荷清单');
    check(!is_dir($payload . '/db'), '变空的目录应被清理');
    check(!is_file($frontend . '/package.json'), '前端清单登记文件应被卸载');
    check(!is_file($frontend . '/' . FrontendPublisher::MANIFEST), '前端清单应被移除');

    fwrite(STDOUT, 'install payload tests passed' . PHP_EOL);
} finally {
    removeTree($root);
}
