<?php

declare(strict_types=1);

use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\MessageProvider;
use plugin\SandIam\app\model\MessageProviderApplication;
use plugin\SandIam\app\model\Organization;
use plugin\SandIam\app\service\MessageProviderConfigCipher;
use plugin\SandIam\app\service\MessageProviderService;
use plugin\sandadmin\exception\ApiException;
use Webman\Config;
use Webman\ThinkOrm\ThinkOrm;

final class T09AcceptanceMessageDriver
{
    /** @var list<array<string,mixed>> */
    public static array $sent = [];

    /** @param array<string,mixed> $context @param array<string,mixed> $config */
    public static function send(string $destination, string $content, array $context, array $config): void
    {
        self::$sent[] = compact('destination', 'content', 'context', 'config');
    }

    /** @param array<string,mixed> $context @param array<string,mixed> $config */
    public static function verify(string $token, array $context, array $config): bool
    {
        return $token === 'captcha-ok' && ($config['tenant'] ?? null) === 't09-tenant';
    }
}

function messagePgFail(string $message): never
{
    fwrite(STDERR, "IAM-T09 message provider PostgreSQL integration failed: {$message}\n");
    exit(1);
}

function messagePgAssert(bool $condition, string $message): void
{
    if (!$condition) {
        messagePgFail($message);
    }
}

function messagePgExpect(callable $callback, string $error, int $status): void
{
    try {
        $callback();
    } catch (ApiException $exception) {
        if ($exception->getCode() === $status && str_contains($exception->getMessage(), $error)) {
            return;
        }
        messagePgFail("expected {$error}/{$status}, received {$exception->getMessage()}/{$exception->getCode()}");
    }
    messagePgFail("expected {$error}, but no exception was thrown");
}

$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server';
$sandIamRoot = dirname(__DIR__, 3);
if (!is_file($hostRoot . '/vendor/autoload.php')) {
    messagePgFail('SandAdmin dependencies are unavailable');
}

chdir($hostRoot);
require $hostRoot . '/vendor/autoload.php';
require $sandIamRoot . '/plugin/sand-iam/app/functions.php';
Config::clear();
support\App::loadAllConfig(['route']);
Config::load($sandIamRoot . '/plugin/sand-iam/config', ['route'], 'plugin.sand-iam');
ThinkOrm::start(null);

$organizationA = Organization::create(['code' => 't09-pg-org-a', 'name' => 'T09 消息组织 A', 'status' => 1]);
$organizationB = Organization::create(['code' => 't09-pg-org-b', 'name' => 'T09 消息组织 B', 'status' => 1]);
$applicationA = Application::create(['organization_id' => (int) $organizationA->id, 'code' => 't09-pg-app-a', 'name' => 'T09 消息应用 A', 'status' => 1]);
$applicationB = Application::create(['organization_id' => (int) $organizationB->id, 'code' => 't09-pg-app-b', 'name' => 'T09 消息应用 B', 'status' => 1]);
$cipher = new MessageProviderConfigCipher();
$encryptedConfig = $cipher->encrypt(['tenant' => 't09-tenant', 'api_key' => 't09-secret-api-key']);
messagePgAssert(!str_contains($encryptedConfig, 't09-secret-api-key'), 'provider config was stored as plaintext');

$emailProvider = MessageProvider::create([
    'organization_id' => (int) $organizationA->id,
    'code' => 't09-email',
    'name' => '验收邮件服务',
    'provider_type' => 'email',
    'driver_code' => 't09_acceptance',
    'encrypted_config' => $encryptedConfig,
    'config_version' => 1,
    'status' => 1,
]);
$emailMount = MessageProviderApplication::create([
    'message_provider_id' => (int) $emailProvider->id,
    'application_id' => (int) $applicationA->id,
    'organization_id' => (int) $organizationA->id,
    'purposes' => ['verification', 'invitation'],
    'template_codes' => ['email_verify' => 'verify-template', 'invitation' => 'invite-template'],
    'priority' => 10,
    'status' => 1,
]);

$service = new MessageProviderService();
messagePgAssert($service->sendCode((int) $applicationA->id, 'email', 'user@example.test', '12345678', ['purpose' => 'email_verify']) === true, 'mounted email provider did not send a verification code');
$firstSend = T09AcceptanceMessageDriver::$sent[0] ?? null;
messagePgAssert(is_array($firstSend), 'message driver did not receive the send call');
messagePgAssert(($firstSend['destination'] ?? null) === 'user@example.test' && ($firstSend['content'] ?? null) === '12345678', 'message destination or content changed');
messagePgAssert(($firstSend['context']['template_code'] ?? null) === 'verify-template', 'verification template code was not selected by purpose');
messagePgAssert(($firstSend['config']['api_key'] ?? null) === 't09-secret-api-key', 'driver did not receive decrypted provider config');
messagePgAssert($service->sendCode((int) $applicationB->id, 'email', 'other@example.test', '87654321', ['purpose' => 'email_verify']) === false, 'provider leaked into an application without a mount');

$emailMount->save(['status' => 2]);
messagePgAssert($service->sendMessage((int) $applicationA->id, 'email', 'invitation', 'user@example.test', 'invite-link') === false, 'disabled mount continued to send');
$emailMount->save(['status' => 1]);
$emailProvider->save(['status' => 2]);
messagePgAssert($service->sendMessage((int) $applicationA->id, 'email', 'invitation', 'user@example.test', 'invite-link') === false, 'disabled provider continued to send');
$emailProvider->save(['status' => 1]);
$service->test($emailProvider, 'test@example.test', ['request_id' => 't09-provider-test']);
messagePgAssert(count(T09AcceptanceMessageDriver::$sent) === 2, 'provider test did not call the deployment driver exactly once');

$captchaProvider = MessageProvider::create([
    'organization_id' => (int) $organizationA->id,
    'code' => 't09-captcha',
    'name' => '验收验证码服务',
    'provider_type' => 'captcha',
    'driver_code' => 't09_acceptance',
    'encrypted_config' => $encryptedConfig,
    'config_version' => 1,
    'status' => 1,
]);
MessageProviderApplication::create([
    'message_provider_id' => (int) $captchaProvider->id,
    'application_id' => (int) $applicationA->id,
    'organization_id' => (int) $organizationA->id,
    'purposes' => ['login'],
    'template_codes' => ['login' => ''],
    'priority' => 20,
    'status' => 1,
]);
$service->verifyCaptcha((int) $applicationA->id, 'captcha-ok', 'login');
messagePgExpect(static fn () => $service->verifyCaptcha((int) $applicationA->id, 'captcha-bad', 'login'), 'SAND_IAM_CAPTCHA_INVALID', 400);
messagePgExpect(static fn () => $service->verifyCaptcha((int) $applicationB->id, 'captcha-ok', 'login'), 'SAND_IAM_CAPTCHA_UNAVAILABLE', 503);

$crossOrganizationRejected = false;
try {
    MessageProviderApplication::create([
        'message_provider_id' => (int) $emailProvider->id,
        'application_id' => (int) $applicationB->id,
        'organization_id' => (int) $organizationA->id,
        'purposes' => ['verification'],
        'template_codes' => ['verification' => ''],
        'priority' => 30,
        'status' => 1,
    ]);
} catch (Throwable $exception) {
    $crossOrganizationRejected = str_contains($exception->getMessage(), 'fk_sand_iam_message_mount_application_organization');
}
messagePgAssert($crossOrganizationRejected, 'database accepted a cross-organization provider mount');
messagePgAssert(!array_key_exists('encrypted_config', $emailProvider->toArray()), 'provider model serialization exposed encrypted config');

fwrite(STDOUT, "IAM-T09 message provider PostgreSQL integration passed\n");
