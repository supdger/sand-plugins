<?php

declare(strict_types=1);

namespace plugin\sandadmin\exception { final class ApiException extends \RuntimeException {} }
namespace plugin\sandadmin\service { #[\Attribute(\Attribute::TARGET_METHOD)] final class Permission { public function __construct(string $name, string $code) {} } }
namespace support {
    final class Response { public function __construct(public mixed $data = null, public string $message = '') {} }
    final class Request
    {
        /** @param array<string,mixed> $post @param array<string,mixed> $get @param array<string,mixed> $headers */
        public function __construct(private array $post = [], private array $get = [], private array $headers = []) {}
        public function post(?string $key = null, mixed $default = null): mixed { return $key === null ? $this->post : ($this->post[$key] ?? $default); }
        public function get(?string $key = null, mixed $default = null): mixed { return $key === null ? $this->get : ($this->get[$key] ?? $default); }
        public function header(string $key, mixed $default = null): mixed { return $this->headers[$key] ?? $default; }
    }
}
namespace plugin\sandadmin\basic { class BaseController { protected function success(mixed $data = null, string $message = ''): \support\Response { return new \support\Response($data, $message); } } }
namespace plugin\SandIam\app\admin\support {
    final class AdminOrganizationAccess
    {
        public function __construct(int $adminId, ?array $admin) {}
        public function isSuperAdmin(): bool { return false; }
        /** @return list<int> */ public function organizationIds(): array { return [1]; }
        public function assertOrganization(int $id): void { if ($id !== 1) throw new \plugin\sandadmin\exception\ApiException('SAND_IAM_ORGANIZATION_ACCESS_DENIED', 403); }
        public function assertApplication(int $id): void { if ($id !== 10) throw new \plugin\sandadmin\exception\ApiException('SAND_IAM_APPLICATION_ACCESS_DENIED', 403); }
    }
}
namespace plugin\SandIam\app\model {
    #[\AllowDynamicProperties] final class InitializationDraft
    {
        /** @var array<int,self> */ public static array $rows = [];
        public static function find(int $id): ?self { return self::$rows[$id] ?? null; }
        /** @return array<string,mixed> */ public function toArray(): array { return get_object_vars($this); }
    }
    final class InitializationRun {}
}
namespace plugin\SandIam\app\service {
    final class InitializationService
    {
        /** @var list<string> */ public static array $calls = [];
        /** @param array<string,mixed> $manifest @return array<string,mixed> */
        public function preview(array $manifest): array { self::$calls[] = 'preview'; return ['organization_id' => 1, 'application_id' => ($manifest['application_code'] ?? '') === 'foreign' ? 11 : 10]; }
        /** @param array<string,mixed> $manifest @return array<string,mixed> */
        public function saveDraft(array $manifest, int $adminId, string $requestId): array { self::$calls[] = 'save'; return ['draft_id' => 3, 'revision' => 1, 'status' => 1, 'manifest_hash' => 'a']; }
        /** @param array<string,mixed> $manifest @return array<string,mixed> */
        public function updateDraft(int $id, int $revision, array $manifest, int $adminId, string $requestId): array { self::$calls[] = 'update'; return ['draft_id' => $id, 'revision' => $revision + 1, 'status' => 1, 'manifest_hash' => 'b']; }
        /** @return array<string,mixed> */
        public function disableDraft(int $id, int $revision, int $adminId, string $requestId): array { self::$calls[] = 'disable'; return ['draft_id' => $id, 'revision' => $revision + 1, 'status' => 2, 'manifest_hash' => 'b']; }
    }
}
namespace {
    use plugin\SandIam\app\admin\controller\InitializationController;
    use plugin\SandIam\app\model\InitializationDraft;
    use plugin\SandIam\app\service\InitializationService;
    use plugin\sandadmin\exception\ApiException;
    use support\Request;

    require_once dirname(__DIR__) . '/app/service/RequestId.php';
    require_once dirname(__DIR__) . '/app/admin/controller/InitializationController.php';

    function initializationDraftControllerAssert(bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, $message . PHP_EOL); exit(1); } }
    function initializationDraftControllerRequest(array $post): Request { return new Request($post, [], ['check_admin' => ['id' => 7], 'X-Request-Id' => 'initialization-draft-controller-20260908']); }
    $allowed = new InitializationDraft(); $allowed->id = 3; $allowed->organization_id = 1; $allowed->application_id = 10; $allowed->revision = 1; $allowed->manifest = ['safe' => true];
    $foreign = new InitializationDraft(); $foreign->id = 4; $foreign->organization_id = 1; $foreign->application_id = 11; $foreign->revision = 1; $foreign->manifest = ['safe' => true];
    InitializationDraft::$rows = [3 => $allowed, 4 => $foreign];
    $controller = new InitializationController();

    $saved = $controller->save(initializationDraftControllerRequest(['manifest' => ['application_code' => 'allowed']]));
    initializationDraftControllerAssert(($saved->data['draft_id'] ?? 0) === 3 && !array_key_exists('manifest', $saved->data), 'draft save response exposed a manifest or did not return its public result');
    try { $controller->save(initializationDraftControllerRequest(['manifest' => ['application_code' => 'foreign']])); initializationDraftControllerAssert(false, 'controller accepted a foreign application draft'); }
    catch (ApiException $exception) { initializationDraftControllerAssert($exception->getCode() === 403 && str_contains($exception->getMessage(), 'SAND_IAM_APPLICATION_ACCESS_DENIED'), 'foreign draft save lost stable scope denial'); }
    initializationDraftControllerAssert(InitializationService::$calls === ['preview', 'save', 'preview'], 'foreign draft save reached the write service');

    $updated = $controller->update(initializationDraftControllerRequest(['id' => 3, 'revision' => 1, 'manifest' => ['application_code' => 'allowed']]));
    initializationDraftControllerAssert(($updated->data['revision'] ?? 0) === 2 && !array_key_exists('manifest', $updated->data), 'draft update response exposed a manifest or ignored revision');
    try { $controller->disable(initializationDraftControllerRequest(['id' => 4, 'revision' => 1])); initializationDraftControllerAssert(false, 'controller accepted a foreign draft disable'); }
    catch (ApiException $exception) { initializationDraftControllerAssert($exception->getCode() === 403 && str_contains($exception->getMessage(), 'SAND_IAM_APPLICATION_ACCESS_DENIED'), 'foreign draft disable lost stable scope denial'); }
    initializationDraftControllerAssert(InitializationService::$calls === ['preview', 'save', 'preview', 'update'], 'foreign draft disable reached the write service');

    foreach (['update', 'disable'] as $operation) {
        foreach ([true, false, 1.9, '1bad', '1.0', '1e0', null, [], 0, -1, (string) PHP_INT_MAX . '0'] as $revision) {
            $before = InitializationService::$calls;
            try {
                $controller->$operation(initializationDraftControllerRequest(['id' => 3, 'revision' => $revision, 'manifest' => ['application_code' => 'allowed']]));
                throw new \RuntimeException('invalid draft revision reached mutation');
            } catch (ApiException $exception) {
                initializationDraftControllerAssert($exception->getCode() === 400 && $exception->getMessage() === 'SAND_IAM_INITIALIZATION_DRAFT_REVISION_INVALID', 'revision error changed');
            }
            initializationDraftControllerAssert(InitializationService::$calls === $before, 'invalid revision called service');
        }
        $result = $controller->$operation(initializationDraftControllerRequest(['id' => 3, 'revision' => '1', 'manifest' => ['application_code' => 'allowed']]));
        initializationDraftControllerAssert($result->data['revision'] === 2, 'form revision was not normalized');
    }
    $reflection = new \ReflectionClass(InitializationController::class);
    foreach (['save', 'update', 'disable', 'draftIndex', 'draftRead'] as $method) initializationDraftControllerAssert(count($reflection->getMethod($method)->getAttributes()) === 1, "{$method} lacks its permission attribute");
    echo "initialization draft controller behavior non-PG test passed\n";
}
