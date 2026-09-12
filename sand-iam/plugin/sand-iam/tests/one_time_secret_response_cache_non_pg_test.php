<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

/**
 * One-time plaintext secrets and issued authentication tokens must not be
 * retained by browsers or legacy HTTP/1.0 intermediary caches.
 */
$package = dirname(__DIR__);

function oneTimeSecretCacheAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

/** @return string */
function oneTimeSecretSource(string $package, string $relative): string
{
    $source = file_get_contents($package . '/' . $relative);
    oneTimeSecretCacheAssert(is_string($source), "one-time secret source is unreadable: {$relative}");
    return $source;
}

$requirements = [
    'app/admin/controller/OAuthClientController.php' => 2,
    'app/admin/controller/CredentialController.php' => 2,
    'app/admin/controller/DeveloperController.php' => 1,
    'app/admin/controller/OAuthRegistrationTokenController.php' => 1,
    'app/admin/controller/FederationAdminController.php' => 1,
    'app/admin/controller/WebhookController.php' => 2,
    'app/api/controller/AuthController.php' => 4,
];

foreach ($requirements as $relative => $minimum) {
    $source = oneTimeSecretSource($package, $relative);
    $noStore = substr_count($source, "withHeader('Cache-Control', 'no-store')");
    $noCache = substr_count($source, "withHeader('Pragma', 'no-cache')");
    oneTimeSecretCacheAssert($noStore >= $minimum, "{$relative} protects fewer than {$minimum} one-time secret responses with no-store");
    oneTimeSecretCacheAssert($noCache >= $minimum, "{$relative} protects fewer than {$minimum} one-time secret responses with no-cache");
}

$oauthClient = oneTimeSecretSource($package, 'app/admin/controller/OAuthClientController.php');
foreach (['function save(', 'function rotateSecret(', "'client_secret'", '客户端密钥仅此一次展示', '新密钥仅此一次展示'] as $needle) {
    oneTimeSecretCacheAssert(str_contains($oauthClient, $needle), "OAuth client one-time secret flow is missing {$needle}");
}
foreach (['IdempotencyService::fingerprint($payload)', "'oauth_client.create'", "'oauth_client.secret_rotate'", "->lock(true)->find()", "'secret_available' => false"] as $needle) {
    oneTimeSecretCacheAssert(str_contains($oauthClient . oneTimeSecretSource($package, 'app/service/IdempotencyService.php'), $needle), "OAuth client retry safety is missing {$needle}");
}

$credential = oneTimeSecretSource($package, 'app/admin/controller/CredentialController.php');
foreach (['function issue(', 'function rotate(', '凭证只显示一次', '新凭证只显示一次'] as $needle) {
    oneTimeSecretCacheAssert(str_contains($credential, $needle), "machine credential one-time secret flow is missing {$needle}");
}

$auth = oneTimeSecretSource($package, 'app/api/controller/AuthController.php');
foreach (['function register(', 'function login(', 'function refresh(', 'private function sensitive('] as $needle) {
    oneTimeSecretCacheAssert(str_contains($auth, $needle), "authentication token response is missing {$needle}");
}

echo 'one-time secret response cache checks passed' . PHP_EOL;
