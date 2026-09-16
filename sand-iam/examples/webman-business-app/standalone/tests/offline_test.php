<?php

declare(strict_types=1);

use Example\Standalone\AuditWriter;
use Example\Standalone\Controller;
use Example\Standalone\HttpProblem;
use Example\Standalone\Repository;
use Example\Standalone\WorkItem;

require dirname(__DIR__) . '/vendor/autoload.php';

final class FakeAuditWriter implements AuditWriter
{
    /** @var list<array{action:string,outcome:string,reason:?string}> */
    public array $events = [];

    public function writeSuccess(PDO $database, string $action, WorkItem $item, string $actorReference, string $requestId): void
    {
        $this->events[] = ['action' => $action, 'outcome' => 'allowed', 'reason' => null];
    }

    public function writeEvent(string $action, string $outcome, ?WorkItem $item, ?string $reasonCode, string $actorReference, string $requestId): void
    {
        $this->events[] = ['action' => $action, 'outcome' => $outcome, 'reason' => $reasonCode];
    }
}

final class FakeRepository implements Repository
{
    public int $closeCalls = 0;
    public int $findCalls = 0;
    public ?string $lastCloseAction = null;

    public function __construct(private WorkItem $item) {}

    public function health(): void {}

    public function find(int $id): WorkItem
    {
        $this->findCalls++;
        if ($id !== $this->item->id) throw new RuntimeException('not found');
        return $this->item;
    }

    public function close(int $id, callable $authorize, AuditWriter $auditWriter, string $action, string $requestId): WorkItem
    {
        $loaded = $this->find($id);
        $authorize($loaded);
        $this->lastCloseAction = $action;
        $this->closeCalls++;
        return $this->item = new WorkItem($loaded->id, $loaded->organizationId, $loaded->ownerIdentityId, 'closed', $loaded->version + 1);
    }
}

function expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function controller(FakeRepository $repository, callable $authorizer, ?FakeAuditWriter &$audit = null): Controller
{
    $audit = new FakeAuditWriter();
    return new Controller($repository, $audit, $authorizer);
}

$headers = ['authorization' => 'Bearer not-logged', 'x-request-id' => 'offline-test-close-001'];
foreach (['GET' => '', 'POST' => '/close'] as $method => $suffix) {
    $overflowRepository = new FakeRepository(new WorkItem(PHP_INT_MAX, 101, 202, 'open', 1));
    $overflow = controller($overflowRepository, static fn (): string => '42', $overflowAudit)
        ->handle($method, '/items/' . PHP_INT_MAX . '0' . $suffix, $headers, '{}');
    expect($overflow['status'] === 400 && ($overflow['body']['error'] ?? '') === 'invalid_item_id', 'overflow item ID must be rejected');
    expect($overflowRepository->findCalls === 0 && $overflowRepository->closeCalls === 0, 'overflow item ID must not access a different item');
    expect(($overflowAudit->events[0]['reason'] ?? '') === 'invalid_item_id', 'invalid item ID denial audit missing');
}
$maxRepository = new FakeRepository(new WorkItem(PHP_INT_MAX, 101, 202, 'open', 1));
$maxRead = controller($maxRepository, static fn (): string => '42')
    ->handle('GET', '/items/' . PHP_INT_MAX, $headers, '');
expect($maxRead['status'] === 200 && $maxRead['body']['id'] === PHP_INT_MAX, 'maximum valid item ID must remain exact');
$deniedRepository = new FakeRepository(new WorkItem(1, 101, 202, 'open', 1));
$denied = controller($deniedRepository, static fn (): string => throw new HttpProblem(403, 'access_denied'), $deniedAudit)
    ->handle('POST', '/items/1/close', $headers, '{}');
expect($denied['status'] === 403 && $deniedRepository->closeCalls === 0, 'deny must not close a work item');
expect($deniedAudit->events === [['action' => 'standalone_work_item.close', 'outcome' => 'denied', 'reason' => 'access_denied']], 'close deny audit is missing');

$authenticationRepository = new FakeRepository(new WorkItem(1, 101, 202, 'open', 1));
$authentication = controller($authenticationRepository, static fn (): string => throw new HttpProblem(401, 'SAND_IAM_AUTHENTICATION_FAILED'), $authenticationAudit)
    ->handle('POST', '/items/1/close', $headers, '{}');
expect($authentication['status'] === 401 && $authenticationRepository->closeCalls === 0, 'authentication rejection must not close a work item');
expect($authenticationAudit->events[0]['reason'] === 'SAND_IAM_AUTHENTICATION_FAILED', 'authentication rejection audit is missing');

$revokedRepository = new FakeRepository(new WorkItem(1, 101, 202, 'open', 1));
$revoked = controller($revokedRepository, static fn (): string => throw new HttpProblem(403, 'SAND_IAM_CREDENTIAL_REVOKED'), $revokedAudit)
    ->handle('POST', '/items/1/close', $headers, '{}');
expect($revoked['status'] === 403 && $revokedRepository->closeCalls === 0, 'revocation must not close a work item');
expect($revokedAudit->events[0]['reason'] === 'SAND_IAM_CREDENTIAL_REVOKED', 'revocation audit is missing');

$networkRepository = new FakeRepository(new WorkItem(1, 101, 202, 'open', 1));
$network = controller($networkRepository, static fn (): string => throw new HttpProblem(503, 'authorization_unavailable'), $networkAudit)
    ->handle('POST', '/items/1/close', $headers, '{}');
expect($network['status'] === 503 && $networkRepository->closeCalls === 0, 'authorization failures must fail closed');
expect($networkAudit->events[0]['reason'] === 'authorization_unavailable', 'authorization exception audit is missing');

$missingBearerHeaders = ['x-request-id' => 'offline-test-close-001'];
$getMissingBearerRepository = new FakeRepository(new WorkItem(1, 101, 202, 'open', 1));
$getMissingBearer = controller($getMissingBearerRepository, static fn (): string => '42', $getMissingBearerAudit)
    ->handle('GET', '/items/1', $missingBearerHeaders, '');
expect($getMissingBearer['status'] === 401 && $getMissingBearerRepository->findCalls === 0 && $getMissingBearerRepository->closeCalls === 0, 'GET without bearer must not load or change a work item');
expect($getMissingBearerAudit->events === [['action' => 'standalone_work_item.read', 'outcome' => 'denied', 'reason' => 'authentication_required']], 'GET missing bearer must have one deny audit');

$postMissingBearerRepository = new FakeRepository(new WorkItem(1, 101, 202, 'open', 1));
$postMissingBearer = controller($postMissingBearerRepository, static fn (): string => '42', $postMissingBearerAudit)
    ->handle('POST', '/items/1/close', $missingBearerHeaders, '{}');
expect($postMissingBearer['status'] === 401 && $postMissingBearerRepository->findCalls === 0 && $postMissingBearerRepository->closeCalls === 0, 'POST without bearer must not load or change a work item');
expect($postMissingBearerAudit->events === [['action' => 'standalone_work_item.close', 'outcome' => 'denied', 'reason' => 'authentication_required']], 'POST missing bearer must have one deny audit');

$bodyRepository = new FakeRepository(new WorkItem(1, 101, 202, 'open', 1));
$body = controller($bodyRepository, static fn (): string => '42', $bodyAudit)
    ->handle('POST', '/items/1/close', $headers, '{"organization_id":999}');
expect($body['status'] === 400 && $bodyRepository->findCalls === 0 && $bodyRepository->closeCalls === 0, 'request body must not load or override object scope');
expect($bodyAudit->events === [['action' => 'standalone_work_item.close', 'outcome' => 'denied', 'reason' => 'body_scope_forbidden']], 'scope tampering must have one deny audit');

foreach (['[]', '[1]', '{"unexpected":true}', 'null', '42'] as $invalidBody) {
    $repository = new FakeRepository(new WorkItem(1, 101, 202, 'open', 1));
    $authorizationCalls = 0;
    $result = controller($repository, static function () use (&$authorizationCalls): string {
        $authorizationCalls++;
        return '42';
    }, $invalidBodyAudit)->handle('POST', '/items/1/close', $headers, $invalidBody);
    expect($result['status'] === 400 && ($result['body']['error'] ?? '') === 'invalid_body', 'non-empty object or non-object close body must be rejected: ' . $invalidBody);
    expect($repository->findCalls === 0 && $repository->closeCalls === 0 && $authorizationCalls === 0, 'invalid close body must not access business state or authorization');
    expect($invalidBodyAudit->events === [['action' => 'standalone_work_item.close', 'outcome' => 'denied', 'reason' => 'invalid_body']], 'invalid close body must have one deny audit');
}

$readRepository = new FakeRepository(new WorkItem(1, 101, 202, 'open', 1));
$readDenied = controller($readRepository, static fn (): string => throw new HttpProblem(403, 'access_denied'), $readDeniedAudit)
    ->handle('GET', '/items/1', $headers, '');
expect($readDenied['status'] === 403, 'read must not disclose an unauthorized work item');
expect($readDeniedAudit->events[0]['action'] === 'standalone_work_item.read', 'read deny audit is missing');

$readAllowedRepository = new FakeRepository(new WorkItem(1, 101, 202, 'open', 1));
$readAllowed = controller($readAllowedRepository, static fn (): string => '42', $readAllowedAudit)
    ->handle('GET', '/items/1', $headers, '');
expect($readAllowed['status'] === 200 && $readAllowedAudit->events[0]['outcome'] === 'allowed', 'read allow audit is missing');

$allowedRepository = new FakeRepository(new WorkItem(1, 101, 202, 'open', 1));
$allowed = controller($allowedRepository, static fn (): string => '42')
    ->handle('POST', '/items/1/close', $headers, '{}');
expect($allowed['status'] === 200 && $allowedRepository->closeCalls === 1, 'allowed close must update exactly once');
expect(($allowed['body']['state'] ?? '') === 'closed' && ($allowed['body']['version'] ?? 0) === 2, 'allowed close result is invalid');

$configuredRepository = new FakeRepository(new WorkItem(1, 101, 202, 'open', 1));
$configuredAudit = new FakeAuditWriter();
$configured = new Controller(
    $configuredRepository,
    $configuredAudit,
    static fn (): string => '42',
    'sand_iam_acceptance_0123456789abcdef_read',
    'sand_iam_acceptance_0123456789abcdef_close',
);
$configuredRead = $configured->handle('GET', '/items/1', $headers, '');
expect($configuredRead['status'] === 200 && $configuredAudit->events[0]['action'] === 'sand_iam_acceptance_0123456789abcdef_read', 'configured read API code was not used end to end');
$configuredClose = $configured->handle('POST', '/items/1/close', $headers, '{}');
expect($configuredClose['status'] === 200 && $configuredRepository->lastCloseAction === 'sand_iam_acceptance_0123456789abcdef_close', 'configured close API code was not used end to end');

echo "offline standalone consumer tests: PASS\n";
