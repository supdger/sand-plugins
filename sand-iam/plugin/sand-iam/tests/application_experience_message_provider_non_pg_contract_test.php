<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

/** T09 source contract floor. This test does not connect to a database. */
$package = dirname(__DIR__);
$root = dirname(__DIR__, 3);
$checks = [
    $package . '/app/admin/controller/ApplicationExperienceController.php' => [
        "['password', 'passkey']",
        "['open', 'invite', 'disabled']",
        '登录方式引用的身份源未挂载或已停用',
        '注册字段必须包含用户名',
        '对外链接必须是有效 HTTPS 地址',
    ],
    $package . '/app/api/controller/ExperienceController.php' => [
        'application_experience_enabled',
        "'brand_name' =>",
        "'login_methods' =>",
        "withHeader('Cache-Control', 'public, max-age=60')",
    ],
    $package . '/app/admin/controller/MessageProviderController.php' => [
        "['email', 'sms', 'captcha', 'notification']",
        'config_configured',
        'MessageProviderConfigCipher())->encrypt($config)',
        '消息服务和接入应用必须属于同一客户主体',
        '接收地址、验证码和挑战令牌未写入审计',
        'public function options(',
        'public function mounts(',
    ],
    $package . '/app/service/MessageProviderConfigCipher.php' => [
        'sodium_crypto_secretbox(',
        'sodium_crypto_secretbox_open(',
        'message_encryption_key_version',
        'message_encryption_keys',
    ],
    $package . '/app/service/MessageProviderService.php' => [
        'message_provider_enabled',
        'provider($applicationId, \'captcha\', $action)',
        'sendMessage($applicationId, $type, \'verification\'',
        "config('plugin.sand-iam.app.message_drivers'",
        'SAND_IAM_MESSAGE_PROVIDER_UNAVAILABLE',
    ],
    $package . '/app/middleware/MessageProviderSensitiveMiddleware.php' => [
        'SAND_IAM_MESSAGE_PROVIDER_OPERATION_FAILED',
        "'admin', 'redacted'",
        "withHeader('Cache-Control', 'no-store')",
        'error boundary must never rethrow request secrets',
    ],
    $package . '/app/service/HumanAuthService.php' => [
        'assertExperienceAllows($application, \'register\')',
        'assertExperienceAllows($application, \'login\')',
        "['captcha_token']",
        'MessageProviderService())->sendCode(',
        'SAND_IAM_AUTH_METHOD_DISABLED',
    ],
    $package . '/config/route.php' => [
        "'application-experience' => ApplicationExperienceController::class",
        'message-provider/configure',
        'message-provider/test',
        "'/api/sand-iam/v1/experience'",
    ],
    $package . '/config/app.php' => [
        "env('SAND_IAM_APPLICATION_EXPERIENCE_ENABLED', 0)",
        "env('SAND_IAM_MESSAGE_PROVIDER_ENABLED', 0)",
        "env('SAND_IAM_MESSAGE_DRIVERS', '')",
    ],
];

foreach ($checks as $file => $fragments) {
    $content = file_get_contents($file);
    if (!is_string($content)) { fwrite(STDERR, "unreadable {$file}\n"); exit(1); }
    foreach ($fragments as $fragment) {
        if (!str_contains($content, $fragment)) { fwrite(STDERR, "missing {$fragment} in {$file}\n"); exit(1); }
    }
}

$controller = file_get_contents($package . '/app/admin/controller/MessageProviderController.php');
if (!is_string($controller) || preg_match('/return\s*\[[^;]*[\'\"]encrypted_config[\'\"]\s*=>/s', $controller)) {
    fwrite(STDERR, "message provider management DTO may expose encrypted_config\n");
    exit(1);
}

$migrationName = '011_application_experience_message_provider.pgsql';
$sourceMigration = $root . '/migrations/' . $migrationName;
$packageMigration = $package . '/migrations/' . $migrationName;
if (!is_file($sourceMigration) || !is_file($packageMigration) || hash_file('sha256', $sourceMigration) !== hash_file('sha256', $packageMigration)) {
    fwrite(STDERR, "root/plugin migration differs: {$migrationName}\n");
    exit(1);
}
$migration = file_get_contents($sourceMigration);
foreach (['sand_iam_application_experience', 'sand_iam_message_provider', 'sand_iam_message_provider_application', 'require_captcha', 'JSONB'] as $fragment) {
    if (!is_string($migration) || !str_contains($migration, $fragment)) { fwrite(STDERR, "migration missing {$fragment}\n"); exit(1); }
}
if (preg_match('/\bAUTO_INCREMENT\b|\bUNSIGNED\b|\bENGINE\s*=/i', (string) $migration)) {
    fwrite(STDERR, "migration contains non-PostgreSQL syntax\n");
    exit(1);
}

echo "application experience and message provider non-PG contract checks passed\n";
