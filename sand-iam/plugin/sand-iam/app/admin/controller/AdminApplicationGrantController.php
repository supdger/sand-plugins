<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\ApplicationResourceController;
use plugin\SandIam\app\model\AdminApplicationGrant;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\service\AuditWriter;
use plugin\SandIam\app\service\RequestId;
use plugin\sandadmin\app\model\system\SystemUser;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class AdminApplicationGrantController extends ApplicationResourceController
{
    protected string $modelClass = AdminApplicationGrant::class;
    protected array $writeFields = ['admin_user_id', 'application_id', 'status'];
    protected array $requiredFields = ['admin_user_id', 'application_id'];
    protected string $resourceType = 'admin_application_grant';
    protected ?string $keywordField = null;

    #[Permission('SandIAM 应用管理员委派列表', 'sand_iam:admin_application_grant:index')]
    public function index(Request $request): Response { return parent::index($request); }

    #[Permission('SandIAM 应用管理员委派读取', 'sand_iam:admin_application_grant:read')]
    public function read(Request $request): Response { return parent::read($request); }

    #[Permission('SandIAM 应用管理员委派保存', 'sand_iam:admin_application_grant:save')]
    public function save(Request $request): Response
    {
        try {
            return parent::save($request);
        } catch (\Throwable $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'unique')) {
                throw new ApiException('SAND_IAM_ADMIN_APPLICATION_GRANT_CONFLICT: 该后台管理员已经拥有此应用的委派记录', 409);
            }
            throw $exception;
        }
    }

    #[Permission('SandIAM 应用管理员委派更新', 'sand_iam:admin_application_grant:update')]
    public function update(Request $request): Response { return parent::update($request); }

    #[Permission('SandIAM 应用管理员委派停用', 'sand_iam:admin_application_grant:disable')]
    public function disable(Request $request): Response { return parent::disable($request); }

    #[Permission('SandIAM 应用管理员委派保存', 'sand_iam:admin_application_grant:save')]
    public function adminOptions(Request $request): Response
    {
        $access = $this->access();
        if (!$access->isSuperAdmin() && $access->organizationIds() === []) {
            $access->assertOrganization(0);
        }
        $id = (int) $request->input('id', 0);
        $keyword = trim((string) $request->input('keywords', ''));
        if ($id <= 0 && mb_strlen($keyword) < 2) {
            return $this->success([]);
        }
        $query = SystemUser::where('status', 1)->field(['id', 'username', 'realname']);
        if ($id > 0) {
            $query->where('id', $id);
        } else {
            $keyword = mb_substr($keyword, 0, 64);
            $query->where(static function ($scope) use ($keyword): void {
                $scope->whereLike('username', '%' . $keyword . '%')->whereOr('realname', 'like', '%' . $keyword . '%');
            });
        }
        $rows = array_map(static function (SystemUser $user): array {
            $name = trim((string) ($user->realname ?: $user->username));
            return ['id' => (int) $user->id, 'name' => $name !== '' ? $name : '未命名后台管理员', 'username' => (string) $user->username];
        }, $query->order('id')->limit(20)->select()->all());
        return $this->success($rows);
    }

    protected function payload(Request $request, bool $updating): array
    {
        $payload = parent::payload($request, $updating);
        if ($updating) unset($payload['admin_user_id'], $payload['application_id']);
        return $payload;
    }

    protected function assertReferences(array $payload, ?object $existing = null): void
    {
        $adminUserId = (int) ($payload['admin_user_id'] ?? $existing?->admin_user_id ?? 0);
        $applicationId = (int) ($payload['application_id'] ?? $existing?->application_id ?? 0);
        if ($adminUserId <= 0 || SystemUser::where('id', $adminUserId)->where('status', 1)->find() === null) {
            throw new ApiException('SAND_IAM_VALIDATION_ERROR: 请选择有效的后台管理员账号', 400);
        }
        if (!Application::where('id', $applicationId)->where('status', 1)->find()) {
            throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 所选接入应用不存在或已停用', 400);
        }
    }

    protected function scopeIndexToOrganizations(object $query): void
    {
        if ($this->access()->isSuperAdmin()) return;
        $organizationIds = $this->access()->organizationIds();
        if ($organizationIds === []) {
            $query->whereRaw('1 = 0');
            return;
        }
        $query->whereIn('application_id', Application::whereIn('organization_id', $organizationIds)->column('id'));
    }

    protected function assertPayloadAccess(array $payload, ?object $existing = null): void
    {
        $applicationId = (int) ($payload['application_id'] ?? $existing?->application_id ?? 0);
        $application = Application::find($applicationId);
        $this->access()->assertOrganization($application ? (int) $application->organization_id : 0);
    }

    protected function assertModelAccess(object $model): void
    {
        $application = Application::find((int) ($model->application_id ?? 0));
        $this->access()->assertOrganization($application ? (int) $application->organization_id : 0);
    }

    protected function audit(string $verb, int $id, Request $request): void
    {
        $grant = AdminApplicationGrant::find($id);
        $application = $grant === null ? null : Application::find((int) $grant->application_id);
        $token = $request->header('check_admin', []);
        $adminId = is_array($token) ? (int) ($token['id'] ?? 0) : 0;
        (new AuditWriter())->write(
            'admin',
            (string) $adminId,
            $application ? (int) $application->organization_id : null,
            $application ? (int) $application->id : null,
            'admin_application_grant.' . $verb,
            'admin_application_grant',
            $id,
            'succeeded',
            RequestId::fromRequestCached($request),
        );
    }
}
