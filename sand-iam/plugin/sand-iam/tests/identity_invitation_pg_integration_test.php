<?php

declare(strict_types=1);

use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\AuthPolicy;
use plugin\SandIam\app\model\IdentityAuth;
use plugin\SandIam\app\model\IdentityGroup;
use plugin\SandIam\app\model\IdentityGroupMember;
use plugin\SandIam\app\model\IdentityInvitation;
use plugin\SandIam\app\model\MessageProvider;
use plugin\SandIam\app\model\MessageProviderApplication;
use plugin\SandIam\app\model\Organization;
use plugin\SandIam\app\service\IdentityInvitationService;
use plugin\SandIam\app\service\MessageProviderConfigCipher;
use plugin\sandadmin\exception\ApiException;
use Webman\Config;
use Webman\ThinkOrm\ThinkOrm;

final class T10InvitationMessageDriver
{
    /** @var list<string> */
    public static array $contents = [];

    /** @param array<string,mixed> $context @param array<string,mixed> $config */
    public static function send(string $destination, string $content, array $context, array $config): void
    {
        if (($config['tenant'] ?? null) !== 't10-invitation-tenant') {
            throw new RuntimeException('unexpected invitation provider config');
        }
        self::$contents[] = $content;
    }
}

function invitationPgFail(string $message): never
{
    fwrite(STDERR, "IAM-T10 invitation PostgreSQL integration failed: {$message}\n");
    exit(1);
}

function invitationPgAssert(bool $condition, string $message): void
{
    if (!$condition) {
        invitationPgFail($message);
    }
}

function invitationPgExpect(callable $callback, string $error, int $status): void
{
    try {
        $callback();
    } catch (ApiException $exception) {
        if ($exception->getCode() === $status && str_contains($exception->getMessage(), $error)) {
            return;
        }
        invitationPgFail("expected {$error}/{$status}, received {$exception->getMessage()}/{$exception->getCode()}");
    }
    invitationPgFail("expected {$error}, but no exception was thrown");
}

function invitationPgToken(string $url): string
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $token = $query['token'] ?? null;
    if (!is_string($token)) {
        invitationPgFail('invitation delivery URL did not contain a token');
    }
    return $token;
}

$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server';
$sandIamRoot = dirname(__DIR__, 3);
if (!is_file($hostRoot . '/vendor/autoload.php')) {
    invitationPgFail('SandAdmin dependencies are unavailable');
}
if ((string) getenv('SAND_IAM_AUTH_PEPPER') === '') {
    putenv('SAND_IAM_AUTH_PEPPER=' . bin2hex(random_bytes(32)));
}

chdir($hostRoot);
require $hostRoot . '/vendor/autoload.php';
require $sandIamRoot . '/plugin/sand-iam/app/functions.php';
Config::clear();
support\App::loadAllConfig(['route']);
Config::load($sandIamRoot . '/plugin/sand-iam/config', ['route'], 'plugin.sand-iam');
ThinkOrm::start(null);

$organization = Organization::create(['code' => 't10-invite-org', 'name' => 'T10 邀请组织', 'status' => 1]);
$applicationA = Application::create(['organization_id' => (int) $organization->id, 'code' => 't10-invite-app-a', 'name' => '律序邀请应用', 'status' => 1]);
$applicationB = Application::create(['organization_id' => (int) $organization->id, 'code' => 't10-invite-app-b', 'name' => '零售邀请应用', 'status' => 1]);
foreach ([$applicationA, $applicationB] as $application) {
    AuthPolicy::create(['application_id' => (int) $application->id, 'registration_enabled' => 2, 'status' => 1]);
}
$group = IdentityGroup::create(['application_id' => (int) $applicationA->id, 'code' => 'invited-users', 'name' => '受邀用户', 'depth' => 1, 'status' => 1]);
$cipher = new MessageProviderConfigCipher();
$provider = MessageProvider::create([
    'organization_id' => (int) $organization->id,
    'code' => 't10-invitation-email',
    'name' => '邀请邮件服务',
    'provider_type' => 'email',
    'driver_code' => 't10_invitation',
    'encrypted_config' => $cipher->encrypt(['tenant' => 't10-invitation-tenant']),
    'config_version' => 1,
    'status' => 1,
]);
foreach ([$applicationA, $applicationB] as $application) {
    MessageProviderApplication::create([
        'message_provider_id' => (int) $provider->id,
        'application_id' => (int) $application->id,
        'organization_id' => (int) $organization->id,
        'purposes' => ['invitation'],
        'template_codes' => ['invitation' => 'invitation-template'],
        'priority' => 10,
        'status' => 1,
    ]);
}

$invitations = new IdentityInvitationService();
$invitationId = $invitations->create((int) $applicationA->id, 'email', 'invited@example.test', [(int) $group->id], 24, '1', 't10-invite-create');
$invitation = IdentityInvitation::find($invitationId);
invitationPgAssert($invitation !== null && (string) $invitation->state === 'pending' && $invitation->encrypted_delivery_token === null, 'delivered invitation did not enter pending state or clear its delivery token');
invitationPgAssert(!array_key_exists('encrypted_target', $invitation->toArray()) && !array_key_exists('token_hash', $invitation->toArray()), 'invitation serialization exposed protected values');
$firstToken = invitationPgToken(T10InvitationMessageDriver::$contents[0] ?? '');
invitationPgExpect(static fn () => $invitations->create((int) $applicationA->id, 'email', 'invited@example.test', [], 24, '1', 't10-invite-duplicate'), 'SAND_IAM_INVITATION_CONFLICT', 409);

$invitations->resend($invitationId, (int) $applicationA->id, '1', 't10-invite-resend');
$secondToken = invitationPgToken(T10InvitationMessageDriver::$contents[1] ?? '');
invitationPgAssert($secondToken !== $firstToken, 'resend reused the previous invitation token');
invitationPgExpect(static fn () => $invitations->accept($firstToken, ['username' => 'old-token-user', 'password' => 'Strong!Password123'], 't10-invite-old-token'), 'SAND_IAM_INVITATION_INVALID', 400);

$accepted = $invitations->accept($secondToken, [
    'username' => 'invited-user',
    'display_name' => '受邀用户一号',
    'password' => 'Strong!Password123',
], 't10-invite-accept');
$auth = IdentityAuth::where('application_id', (int) $applicationA->id)->where('identity_id', (int) $accepted['id'])->find();
invitationPgAssert($auth !== null && (string) $auth->email === 'invited@example.test' && $auth->email_verified_time !== null, 'accepted invitation did not create a verified application account');
invitationPgAssert(IdentityGroupMember::where('identity_group_id', (int) $group->id)->where('identity_id', (int) $accepted['id'])->where('status', 1)->count() === 1, 'accepted invitation did not apply its initial group');
$invitation = IdentityInvitation::find($invitationId);
invitationPgAssert($invitation !== null && (string) $invitation->state === 'accepted' && (int) $invitation->status === 2 && $invitation->consumed_time !== null && $invitation->encrypted_target === null, 'accepted invitation did not consume and redact itself');
invitationPgExpect(static fn () => $invitations->accept($secondToken, ['username' => 'replay-user', 'password' => 'Strong!Password123'], 't10-invite-replay'), 'SAND_IAM_INVITATION_INVALID', 400);

$otherApplicationInvitation = $invitations->create((int) $applicationB->id, 'email', 'invited@example.test', [], 24, '1', 't10-invite-other-app');
invitationPgAssert($otherApplicationInvitation > 0, 'the same email could not be invited independently in another application');
$revokedId = $invitations->create((int) $applicationA->id, 'email', 'revoked@example.test', [], 24, '1', 't10-invite-revoked-create');
$revokedToken = invitationPgToken(T10InvitationMessageDriver::$contents[3] ?? '');
$invitations->revoke($revokedId, (int) $applicationA->id, '1', 't10-invite-revoke');
invitationPgExpect(static fn () => $invitations->accept($revokedToken, ['username' => 'revoked-user', 'password' => 'Strong!Password123'], 't10-invite-revoked-accept'), 'SAND_IAM_INVITATION_INVALID', 400);

fwrite(STDOUT, "IAM-T10 invitation PostgreSQL integration passed\n");
