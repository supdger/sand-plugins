<?php

declare(strict_types=1);

use SandAdmin\Core\FrontendPublisher;

/**
 * FrontendPublisher 行为测试（裸 PHP assert，不依赖 composer autoload）。
 *
 * 发布源路径在类内相对 dirname(__DIR__).'/sandadmin-artd' 解析，
 * 因此测试把发布器复制到临时包里，对合成载荷跑真实代码。
 */

$repositoryRoot = dirname(__DIR__);
$root = sys_get_temp_dir() . '/sand-core-publisher-' . bin2hex(random_bytes(6));
$package = $root . '/pkg';
$source = $package . '/sandadmin-artd';

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

/** @return array<string,string> */
function manifestFiles(string $target, string $name): array
{
    $decoded = json_decode((string) file_get_contents($target . '/' . $name), true);
    check(is_array($decoded) && is_array($decoded['files'] ?? null), "清单不可读：{$target}/{$name}");

    return $decoded['files'];
}

mkdir($package . '/server', 0777, true);
copy($repositoryRoot . '/server/PublishExclusions.php', $package . '/server/PublishExclusions.php');
copy($repositoryRoot . '/server/FrontendPublisher.php', $package . '/server/FrontendPublisher.php');
require_once $package . '/server/PublishExclusions.php';
require_once $package . '/server/FrontendPublisher.php';

$payload = [
    'package.json' => "{\"name\":\"artd\"}\n",
    'src/main.ts' => "export const main = 1\n",
    'src/views/home.vue' => "<template>home</template>\n",
    '.env.example' => "VITE_API_URL = /api\nVITE_LOCK_ENCRYPT_KEY = change-me\n",
    '.env.development.example' => "VITE_API_URL = /api\n",
    'node_modules/ignored.js' => "ignored\n",
    '.DS_Store' => "volatile\n",
];
foreach ($payload as $relative => $content) {
    write($source . '/' . $relative, $content);
}

try {
    // 1. 空目录首次发布
    $target = $root . '/fresh';
    $report = FrontendPublisher::publish($target);
    check($report['status'] === 'published', '首次发布状态应为 published，实际 ' . $report['status']);
    check($report['published'] === 5, '首次发布应写入 5 个文件，实际 ' . $report['published']);
    check(is_file($target . '/' . FrontendPublisher::MANIFEST), '首次发布应生效清单');
    check(!is_file($target . '/' . FrontendPublisher::PENDING), '生效后不应残留 pending');
    check(!is_dir($target . '/node_modules'), 'node_modules 不应发布');
    check(!is_file($target . '/.DS_Store'), '.DS_Store 不应发布');
    $env = (string) file_get_contents($target . '/.env');
    check(str_contains($env, 'VITE_API_URL = /api'), '.env 应初始化 example 键');
    check(!str_contains($env, 'change-me'), 'VITE_LOCK_ENCRYPT_KEY 应在安装期随机生成');
    check(preg_match('/VITE_LOCK_ENCRYPT_KEY = [0-9a-f]{32}/', $env) === 1, '锁屏密钥应为随机十六进制');

    // 2. 幂等重跑
    $report = FrontendPublisher::publish($target);
    check($report['status'] === 'updated' && $report['published'] === 0, '幂等重跑不应重复写入');

    // 3. 非空无清单：内容一致则收养
    $adopt = $root . '/adopt';
    foreach (FrontendPublisher::sourceFiles($source) as $relative => $hash) {
        write($adopt . '/' . $relative, (string) file_get_contents($source . '/' . $relative));
    }
    $report = FrontendPublisher::publish($adopt);
    check($report['status'] === 'adopted', '一致的已提交前端应被收养，实际 ' . $report['status']);
    check($report['published'] === 0, '收养不应覆盖任何文件');
    check(manifestFiles($adopt, FrontendPublisher::MANIFEST) === FrontendPublisher::sourceFiles($source), '收养应补写完整清单');

    // 4. 非空无清单：内容不同则拒绝
    $conflict = $root . '/conflict';
    foreach (FrontendPublisher::sourceFiles($source) as $relative => $hash) {
        write($conflict . '/' . $relative, (string) file_get_contents($source . '/' . $relative));
    }
    write($conflict . '/src/main.ts', "export const main = 999\n");
    $caught = null;
    try {
        FrontendPublisher::publish($conflict);
    } catch (RuntimeException $e) {
        $caught = $e->getMessage();
    }
    check($caught !== null, '内容不同的非空目录应被拒绝');
    check(str_contains($caught, 'src/main.ts'), '冲突消息应列出文件：' . $caught);
    check(str_contains($caught, 'SAND_FRONTEND_PUBLISH=force'), '冲突消息应给出 force 恢复路径：' . $caught);
    check(!is_file($conflict . '/' . FrontendPublisher::MANIFEST), '拒绝时不应生效清单');

    // 5. force 覆盖冲突并重建清单
    putenv('SAND_FRONTEND_PUBLISH=force');
    $report = FrontendPublisher::publish($conflict);
    putenv('SAND_FRONTEND_PUBLISH');
    check($report['status'] === 'forced', 'force 状态应为 forced，实际 ' . $report['status']);
    check(
        hash_file('sha256', $conflict . '/src/main.ts') === hash_file('sha256', $source . '/src/main.ts'),
        'force 应覆盖冲突文件'
    );

    // 6. skip 直接返回
    $skip = $root . '/skip';
    putenv('SAND_FRONTEND_PUBLISH=skip');
    $report = FrontendPublisher::publish($skip);
    putenv('SAND_FRONTEND_PUBLISH');
    check($report['status'] === 'skipped', 'skip 状态应为 skipped，实际 ' . $report['status']);
    check(!is_dir($skip), 'skip 不应创建目标目录');

    // 7. 中断重入：pending 与目标一致则补写清单
    $resume = $root . '/resume-commit';
    $files = FrontendPublisher::sourceFiles($source);
    foreach ($files as $relative => $hash) {
        write($resume . '/' . $relative, (string) file_get_contents($source . '/' . $relative));
    }
    write(
        $resume . '/' . FrontendPublisher::PENDING,
        (string) json_encode(['schema' => 1, 'package' => 'supdger/sand-core', 'version' => 'dev-main', 'pending' => true, 'files' => $files])
    );
    $report = FrontendPublisher::publish($resume);
    check($report['status'] === 'committed', 'pending 一致应补写清单，实际 ' . $report['status']);
    check(!is_file($resume . '/' . FrontendPublisher::PENDING), 'pending 应被 rename 消耗');
    check(manifestFiles($resume, FrontendPublisher::MANIFEST) === $files, '补写清单内容应为源清单');

    // 8. 中断重入：目标与 pending 不一致则重做 copy（不按"已被修改"拒绝）
    $redo = $root . '/resume-redo';
    foreach ($files as $relative => $hash) {
        write($redo . '/' . $relative, (string) file_get_contents($source . '/' . $relative));
    }
    write($redo . '/src/main.ts', "export const main = 0 // 上一次发布未写完\n");
    write(
        $redo . '/' . FrontendPublisher::PENDING,
        (string) json_encode(['schema' => 1, 'package' => 'supdger/sand-core', 'version' => 'dev-main', 'pending' => true, 'files' => $files])
    );
    $report = FrontendPublisher::publish($redo);
    check($report['status'] === 'resumed', 'pending 不一致应重做发布，实际 ' . $report['status']);
    check($report['skipped'] === [], '重做不应把未写完的文件当作宿主修改：' . implode('、', $report['skipped']));
    check(
        hash_file('sha256', $redo . '/src/main.ts') === hash_file('sha256', $source . '/src/main.ts'),
        '重做应补齐未写完的文件'
    );

    // 9. 宿主修改清单内文件：跳过而非覆盖、也不抛异常
    $modified = $root . '/modified';
    FrontendPublisher::publish($modified);
    write($modified . '/src/main.ts', "export const main = 'host'\n");
    $report = FrontendPublisher::publish($modified);
    check($report['skipped'] === ['src/main.ts'], '宿主修改应计入 skipped：' . implode('、', $report['skipped']));
    check(
        (string) file_get_contents($modified . '/src/main.ts') === "export const main = 'host'\n",
        '宿主修改不应被覆盖'
    );

    // 10. 跨版本增删：旧清单有、新载荷没有的文件按旧哈希校验后移除
    $upgrade = $root . '/upgrade';
    write($source . '/src/legacy.ts', "export const legacy = 1\n");
    FrontendPublisher::publish($upgrade);
    check(is_file($upgrade . '/src/legacy.ts'), 'v1 应发布 legacy.ts');
    unlink($source . '/src/legacy.ts');
    write($source . '/src/added.ts', "export const added = 1\n");
    $report = FrontendPublisher::publish($upgrade);
    check($report['removed'] === 1, '下线文件应被移除，实际 ' . $report['removed']);
    check(!is_file($upgrade . '/src/legacy.ts'), 'legacy.ts 应被删除');
    check(is_file($upgrade . '/src/added.ts'), 'added.ts 应被发布');
    check(!array_key_exists('src/legacy.ts', manifestFiles($upgrade, FrontendPublisher::MANIFEST)), '清单应移除下线文件');

    // 11. 下线文件被宿主修改则保留并报告
    $retain = $root . '/retain';
    write($source . '/src/legacy.ts', "export const legacy = 1\n");
    FrontendPublisher::publish($retain);
    write($retain . '/src/legacy.ts', "export const legacy = 'host'\n");
    unlink($source . '/src/legacy.ts');
    $report = FrontendPublisher::publish($retain);
    check($report['retained'] === ['src/legacy.ts'], '被修改的下线文件应保留并报告');
    check(is_file($retain . '/src/legacy.ts'), '被修改的下线文件不应被删除');

    // 12. 删除后重新加入不抛冲突
    write($source . '/src/legacy.ts', "export const legacy = 2\n");
    $report = FrontendPublisher::publish($retain);
    check($report['skipped'] === ['src/legacy.ts'], '重新加入时宿主改动仍受保护：' . implode('、', $report['skipped']));
    $readd = $root . '/readd';
    $report = FrontendPublisher::publish($readd);
    check($report['status'] === 'published', '重新加入的文件应正常发布');
    unlink($source . '/src/legacy.ts');

    // 13. .env 演进：补齐缺失键、不改已有值
    $evolve = $root . '/evolve';
    FrontendPublisher::publish($evolve);
    write($evolve . '/.env', "VITE_API_URL = /custom\n");
    write($source . '/.env.example', "VITE_API_URL = /api\nVITE_LOCK_ENCRYPT_KEY = change-me\nVITE_NEW_KEY = /new\n");
    FrontendPublisher::publish($evolve);
    $env = (string) file_get_contents($evolve . '/.env');
    check(str_contains($env, 'VITE_API_URL = /custom'), '已有键值不应被改写');
    check(substr_count($env, 'VITE_API_URL') === 1, '已有键不应重复追加');
    check(str_contains($env, 'VITE_NEW_KEY = /new'), '缺失键应被补齐');

    fwrite(STDOUT, 'frontend publisher tests passed' . PHP_EOL);
} finally {
    putenv('SAND_FRONTEND_PUBLISH');
    removeTree($root);
}
