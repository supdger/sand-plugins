<?php

declare(strict_types=1);

use plugin\SandIam\app\admin\support\AdminOrganizationAccess;
use plugin\SandIam\app\model\AdminApplicationGrant;
use plugin\SandIam\app\model\AdminOrganizationGrant;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\AuditLog;
use plugin\SandIam\app\model\Environment;
use plugin\SandIam\app\model\Organization;
use plugin\SandIam\app\model\WebhookDelivery;
use plugin\SandIam\app\model\WebhookEndpoint;
use plugin\SandIam\app\model\WorkloadClient;
use plugin\SandIam\app\service\AuditWriter;
use plugin\SandIam\app\service\WebhookSecretCipher;
use plugin\SandIam\app\service\WebhookService;
use plugin\SandIam\app\webhook\WebhookHttpAdapter;
use plugin\sandadmin\exception\ApiException;
use Webman\Config;
use Webman\ThinkOrm\ThinkOrm;

$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server';
$sandIamRoot = dirname(__DIR__, 3);
if (!is_file($hostRoot . '/vendor/autoload.php')) {
    fwrite(STDERR, "IAM-T06 delegation/webhook integration failed: SandAdmin host dependencies are unavailable at {$hostRoot}\n");
    exit(1);
}

putenv('SAND_IAM_WEBHOOK_ENCRYPTION_KEY=' . base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
putenv('SAND_IAM_WEBHOOK_ENCRYPTION_KEY_VERSION=t06-v1');
putenv('SAND_IAM_WEBHOOK_ENCRYPTION_KEYS={}');

chdir($hostRoot);
require $hostRoot . '/vendor/autoload.php';
require $sandIamRoot . '/plugin/sand-iam/app/functions.php';

final class T06FakeWebhookHttpAdapter implements WebhookHttpAdapter
{
    /** @var list<array{url:string,headers:array<string,string>,body:string,timeout:int}> */
    public array $requests = [];
    /** @var list<array{status:int,body:string}> */
    public array $responses = [];

    public function post(string $url, array $headers, string $body, int $timeoutSeconds): array
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body, 'timeout' => $timeoutSeconds];
        return array_shift($this->responses) ?? ['status' => 204, 'body' => ''];
    }
}

function t06Fail(string $message): never
{
    fwrite(STDERR, "IAM-T06 delegation/webhook integration failed: {$message}\n");
    exit(1);
}

function t06Assert(bool $condition, string $message): void
{
    if (!$condition) t06Fail($message);
}

function t06Expect(callable $callback, string $expected): void
{
    try {
        $callback();
    } catch (ApiException $exception) {
        if (str_contains($exception->getMessage(), $expected)) return;
        t06Fail("expected {$expected}, received {$exception->getMessage()}");
    }
    t06Fail("expected {$expected}, but no exception was thrown");
}

function t06ExpectApplicationDenied(callable $callback): void
{
    try {
        $callback();
    } catch (ApiException $exception) {
        if ($exception->getCode() === 403 && str_contains($exception->getMessage(), 'SAND_IAM_APPLICATION_ACCESS_DENIED')) return;
        t06Fail("expected SAND_IAM_APPLICATION_ACCESS_DENIED 403, received {$exception->getCode()} {$exception->getMessage()}");
    }
    t06Fail('expected SAND_IAM_APPLICATION_ACCESS_DENIED 403, but no exception was thrown');
}

Config::clear();
support\App::loadAllConfig(['route']);
Config::load($sandIamRoot . '/plugin/sand-iam/config', ['route'], 'plugin.sand-iam');
ThinkOrm::start(null);

$organization = Organization::create(['code' => 't06-runtime-org', 'name' => 'T06 runtime organization', 'status' => 1]);
$applicationA = Application::create(['organization_id' => (int) $organization->id, 'code' => 't06-app-a', 'name' => 'T06 app A', 'status' => 1]);
$applicationB = Application::create(['organization_id' => (int) $organization->id, 'code' => 't06-app-b', 'name' => 'T06 app B', 'status' => 1]);
$otherOrganization = Organization::create(['code' => 't06-other-org', 'name' => 'T06 other organization', 'status' => 1]);
$applicationC = Application::create(['organization_id' => (int) $otherOrganization->id, 'code' => 't06-app-c', 'name' => 'T06 app C', 'status' => 1]);
$environmentA = Environment::create(['application_id' => (int) $applicationA->id, 'code' => 't06-env-a', 'name' => 'T06 environment A', 'status' => 1]);
$environmentB = Environment::create(['application_id' => (int) $applicationB->id, 'code' => 't06-env-b', 'name' => 'T06 environment B', 'status' => 1]);
$environmentC = Environment::create(['application_id' => (int) $applicationC->id, 'code' => 't06-env-c', 'name' => 'T06 environment C', 'status' => 1]);
$clientA = WorkloadClient::create(['environment_id' => (int) $environmentA->id, 'code' => 't06-client-a', 'name' => 'T06 client A', 'audience' => 't06-a', 'status' => 1]);
$clientB = WorkloadClient::create(['environment_id' => (int) $environmentB->id, 'code' => 't06-client-b', 'name' => 'T06 client B', 'audience' => 't06-b', 'status' => 1]);
$clientC = WorkloadClient::create(['environment_id' => (int) $environmentC->id, 'code' => 't06-client-c', 'name' => 'T06 client C', 'audience' => 't06-c', 'status' => 1]);

$superAdmin = new AdminOrganizationAccess(1, ['id' => 1, 'is_super' => 1]);
foreach ([$applicationA, $applicationB, $applicationC] as $application) {
    $superAdmin->assertApplication((int) $application->id);
}

AdminOrganizationGrant::create(['admin_user_id' => 76, 'organization_id' => (int) $organization->id, 'status' => 1]);
$organizationDelegated = new AdminOrganizationAccess(76, ['id' => 76]);
t06Assert($organizationDelegated->applicationIds() === [(int) $applicationA->id, (int) $applicationB->id], 'organization delegate did not receive all and only target-organization applications');
$organizationDelegated->assertApplication((int) $applicationA->id);
$organizationDelegated->assertApplication((int) $applicationB->id);
t06ExpectApplicationDenied(static fn () => $organizationDelegated->assertApplication((int) $applicationC->id));

$grant = AdminApplicationGrant::create(['admin_user_id' => 77, 'application_id' => (int) $applicationA->id, 'status' => 1]);
$delegated = new AdminOrganizationAccess(77, ['id' => 77]);
t06Assert($delegated->applicationIds() === [(int) $applicationA->id], 'application delegate did not receive exactly its assigned application');
$delegated->assertApplication((int) $applicationA->id);
t06ExpectApplicationDenied(static fn () => $delegated->assertApplication((int) $applicationB->id));
t06ExpectApplicationDenied(static fn () => $delegated->assertApplication((int) $applicationC->id));
$grantedApplicationRows = Application::alias('application')
    ->join('sand_iam_organization organization', 'organization.id = application.organization_id')
    ->field('application.id, application.organization_id, organization.name AS organization_name')
    ->whereIn('application.id', $delegated->applicationIds())
    ->select()
    ->toArray();
t06Assert(count($grantedApplicationRows) === 1, 'application-grant context returned an ungranted same-organization application');
t06Assert((int) ($grantedApplicationRows[0]['id'] ?? 0) === (int) $applicationA->id, 'application-grant context did not return the granted application');
t06Assert((int) ($grantedApplicationRows[0]['organization_id'] ?? 0) === (int) $organization->id, 'application-grant context organization id is incorrect');
t06Assert(($grantedApplicationRows[0]['organization_name'] ?? null) === 'T06 runtime organization', 'application-grant context organization name is missing');
t06Assert((int) $environmentA->application_id === (int) $applicationA->id && (int) $clientA->environment_id === (int) $environmentA->id, 'application delegate fixture does not contain its granted environment/client');
t06Assert((int) $environmentB->application_id === (int) $applicationB->id && (int) $clientB->environment_id === (int) $environmentB->id, 'same-organization denied fixture is incomplete');
t06Assert((int) $environmentC->application_id === (int) $applicationC->id && (int) $clientC->environment_id === (int) $environmentC->id, 'cross-organization denied fixture is incomplete');
$deniedAuditCount = AuditLog::where('actor_ref', '77')->where('action', 'application.access')->where('outcome', 'denied')->whereIn('application_id', [(int) $applicationB->id, (int) $applicationC->id])->count();
t06Assert($deniedAuditCount === 2, 'same-organization and cross-organization application denial audit records are incomplete');
$grant->save(['status' => 2]);
t06ExpectApplicationDenied(static fn () => $delegated->assertApplication((int) $applicationA->id));
$grant->save(['status' => 1]);
$organization->save(['status' => 2]);
t06Assert($delegated->applicationIds() === [], 'disabled organization remained visible through a direct application grant');
t06ExpectApplicationDenied(static fn () => $delegated->assertApplication((int) $applicationA->id));
$organization->save(['status' => 1]);

$fake = new T06FakeWebhookHttpAdapter();
$fake->responses = [
    ['status' => 500, 'body' => 'temporary upstream failure'],
    ['status' => 204, 'body' => 'accepted'],
];
$service = new WebhookService(new WebhookSecretCipher(), $fake, new AuditWriter());
$created = $service->createEndpoint(
    (int) $applicationA->id,
    'identity-events',
    '应用身份变更通知',
    'https://receiver.example.test/sand-iam/events',
    ['identity.updated'],
    5,
    3,
    't06-webhook-create',
);
t06Assert(str_starts_with($created['secret'], 'siwh_') && $created['secret_version'] === 1, 'create did not return a one-time signing secret');
$endpoint = WebhookEndpoint::find($created['id']);
t06Assert($endpoint !== null && !str_contains((string) $endpoint->encrypted_secret, $created['secret']), 'webhook secret was not encrypted at rest');
t06Assert((new WebhookSecretCipher())->decrypt((string) $endpoint->encrypted_secret) === $created['secret'], 'encrypted webhook secret could not be decrypted');

$eventId = 'evt_t06_identity_updated';
$service->enqueue((int) $applicationA->id, 'identity.updated', ['identity_id' => 1001, 'changed_fields' => ['display_name']], $eventId);
$service->enqueue((int) $applicationA->id, 'identity.updated', ['identity_id' => 1001, 'changed_fields' => ['display_name']], $eventId);
t06Assert(WebhookDelivery::where('webhook_endpoint_id', (int) $endpoint->id)->where('event_id', $eventId)->count() === 1, 'event id did not deduplicate endpoint delivery');
t06Expect(static fn () => $service->enqueue((int) $applicationA->id, 'identity.updated', ['access_token' => 'must-not-leak'], 'evt_t06_sensitive_payload'), 'SAND_IAM_WEBHOOK_PAYLOAD_SENSITIVE');

$first = $service->deliverBatch(10);
t06Assert($first === ['claimed' => 1, 'delivered' => 0, 'retried' => 1, 'dead' => 0, 'lease_lost' => 0], 'temporary failure did not schedule a retry');
$delivery = WebhookDelivery::where('event_id', $eventId)->find();
t06Assert($delivery !== null && (int) $delivery->status === 1 && (int) $delivery->attempt_count === 1 && $delivery->response_digest === hash('sha256', 'temporary upstream failure'), 'retry state or response digest is incorrect');
WebhookDelivery::where('id', (int) $delivery->id)->update(['next_attempt_time' => date('Y-m-d H:i:s', time() - 1)]);

$second = $service->deliverBatch(10);
t06Assert($second === ['claimed' => 1, 'delivered' => 1, 'retried' => 0, 'dead' => 0, 'lease_lost' => 0], 'retry did not deliver successfully');
t06Assert(count($fake->requests) === 2, 'fake receiver did not observe both attempts');
$request = $fake->requests[1];
$timestamp = $request['headers']['X-SandIAM-Timestamp'] ?? '';
$signature = $request['headers']['X-SandIAM-Signature'] ?? '';
t06Assert(($request['headers']['X-SandIAM-Event-Id'] ?? '') === $eventId, 'delivery event id header is incorrect');
t06Assert(($request['headers']['X-SandIAM-Secret-Version'] ?? '') === '1', 'delivery secret version header is incorrect');
t06Assert($signature === 'v1=' . hash_hmac('sha256', $timestamp . '.' . $request['body'], $created['secret']), 'delivery signature does not cover exact timestamp and raw body');

$rotated = $service->rotateSecret((int) $endpoint->id, (int) $applicationA->id, 't06-webhook-rotate');
t06Assert($rotated['secret_version'] === 2 && $rotated['secret'] !== $created['secret'], 'secret rotation did not advance the endpoint version');
$rotatedEndpoint = WebhookEndpoint::find((int) $endpoint->id);
t06Assert($rotatedEndpoint !== null && (new WebhookSecretCipher())->decrypt((string) $rotatedEndpoint->encrypted_secret) === $rotated['secret'], 'rotated secret was not stored correctly');

$auditActions = AuditLog::where('application_id', (int) $applicationA->id)->column('action');
foreach (['webhook.create', 'webhook.delivery', 'webhook.secret_rotate'] as $action) {
    t06Assert(in_array($action, $auditActions, true), "missing webhook audit action {$action}");
}

echo "delegation and webhook integration passed\n";
