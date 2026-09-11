<?php

declare(strict_types=1);

use plugin\SandIam\app\service\IdentityImportService;
use plugin\SandIam\app\service\ImportRowCipher;
use plugin\sandadmin\exception\ApiException;
use Webman\Config;

function t10ImportFail(string $message): never { fwrite(STDERR, "IAM-T10 import crypto/CSV test failed: {$message}\n"); exit(1); }
$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server'; $sandIamRoot = dirname(__DIR__, 3); if (!is_file($hostRoot . '/vendor/autoload.php')) t10ImportFail('SandAdmin dependencies unavailable'); chdir($hostRoot); require $hostRoot . '/vendor/autoload.php'; require $sandIamRoot . '/plugin/sand-iam/app/functions.php';
$keyV1 = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)); putenv('SAND_IAM_IMPORT_ENCRYPTION_KEY=' . $keyV1); putenv('SAND_IAM_IMPORT_ENCRYPTION_KEY_VERSION=v1'); putenv('SAND_IAM_IMPORT_ENCRYPTION_KEYS={}'); Config::clear(); Config::load($sandIamRoot . '/plugin/sand-iam/config', ['route'], 'plugin.sand-iam');
$cipher = new ImportRowCipher(); $row = ['username' => 'zhangsan', 'email' => 'zhangsan@example.test']; $v1 = $cipher->encrypt($row); if (!str_starts_with($v1, 'v1.') || str_contains($v1, 'zhangsan') || $cipher->decrypt($v1) !== $row) t10ImportFail('v1 row encryption failed');
$keyV2 = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)); putenv('SAND_IAM_IMPORT_ENCRYPTION_KEY=' . $keyV2); putenv('SAND_IAM_IMPORT_ENCRYPTION_KEY_VERSION=v2'); putenv('SAND_IAM_IMPORT_ENCRYPTION_KEYS=' . json_encode(['v1' => $keyV1], JSON_THROW_ON_ERROR)); Config::clear(); Config::load($sandIamRoot . '/plugin/sand-iam/config', ['route'], 'plugin.sand-iam'); $v2 = new ImportRowCipher(); if ($v2->decrypt($v1) !== $row || !str_starts_with($v2->encrypt($row), 'v2.')) t10ImportFail('key rotation compatibility failed');
$service = new IdentityImportService($v2); $parse = (new ReflectionClass($service))->getMethod('parse');
$validCsv = "用户名,显示名称,邮箱,手机号,账号状态,用户组代码\nzhangsan,张三,,+8613800000000,正常,\n"; $parsed = $parse->invoke($service, $validCsv, 1, 'create'); if (($parsed[0]['errors'] ?? ['missing']) !== []) t10ImportFail('valid international phone was treated as spreadsheet formula');
$formulaCsv = "用户名,显示名称,邮箱,手机号,账号状态,用户组代码\nzhangsan,=cmd,zhangsan@example.test,,正常,\n"; $parsedFormula = $parse->invoke($service, $formulaCsv, 1, 'create'); if (!in_array('单元格不能以公式字符开头', $parsedFormula[0]['errors'] ?? [], true)) t10ImportFail('formula-like input was not rejected');
$csvMethod = (new ReflectionClass($service))->getMethod('csv'); if ($csvMethod->invoke($service, '=SUM(A1:A2)') !== "'=SUM(A1:A2)") t10ImportFail('export formula hardening failed');
try { $parse->invoke($service, "username,name\na,b\n", 1, 'create'); t10ImportFail('invalid headers were accepted'); } catch (\Throwable $exception) { $previous = $exception->getPrevious(); $actual = $previous instanceof ApiException ? $previous : $exception; if (!str_contains($actual->getMessage(), 'SAND_IAM_IMPORT_HEADERS_INVALID')) t10ImportFail('invalid header error code changed'); }
echo "import crypto and CSV non-PG tests passed\n";
