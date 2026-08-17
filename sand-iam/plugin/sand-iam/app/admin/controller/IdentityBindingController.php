<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\AdminOrganizationAccess;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\Identity;
use plugin\SandIam\app\model\IdentityBinding;
use plugin\SandIam\app\service\AuditWriter;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class IdentityBindingController extends BaseController
{
    #[Permission('SandIAM 身份绑定列表', 'sand_iam:identity_binding:index')]
    public function index(Request $request): Response
    {
        $identity = $this->identity((int) $request->input('identity_id', 0));
        return $this->success(IdentityBinding::where('identity_id', $identity->id)->order('id', 'desc')->select()->toArray());
    }

    #[Permission('SandIAM 身份绑定读取', 'sand_iam:identity_binding:read')]
    public function read(Request $request): Response
    {
        return $this->success($this->binding((int) $request->input('id', 0))->toArray());
    }

    #[Permission('SandIAM 身份绑定保存', 'sand_iam:identity_binding:save')]
    public function save(Request $request): Response
    {
        $identity = $this->identity((int) $request->post('identity_id', 0));
        $providerCode = trim((string) $request->post('provider_code', ''));
        $subject = trim((string) $request->post('subject', ''));
        if (!preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/', $providerCode) || $subject === '' || mb_strlen($subject) > 191) {
            throw new ApiException('SAND_IAM_VALIDATION_ERROR: invalid provider_code or subject', 400);
        }
        $binding = IdentityBinding::where('provider_code', $providerCode)->where('subject', $subject)->find();
        if ($binding && (int) $binding->identity_id !== (int) $identity->id) {
            throw new ApiException('SAND_IAM_VALIDATION_ERROR: identity binding already belongs to another identity', 400);
        }
        if ($binding) {
            $binding->save(['status' => 1]);
        } else {
            $binding = IdentityBinding::create(['identity_id' => $identity->id, 'provider_code' => $providerCode, 'subject' => $subject, 'status' => 1]);
        }
        $this->audit('identity_binding.save', (int) $binding->id, $identity, $request);
        return $this->success(['id' => (int) $binding->id], '已保存');
    }

    #[Permission('SandIAM 身份绑定更新', 'sand_iam:identity_binding:update')]
    public function update(Request $request): Response
    {
        $binding = $this->binding((int) $request->post('id', 0));
        $identity = $this->identity((int) $binding->identity_id);
        $status = (int) $request->post('status', 0);
        if (!in_array($status, [1, 2], true)) {
            throw new ApiException('SAND_IAM_VALIDATION_ERROR: invalid status', 400);
        }
        $binding->save(['status' => $status]);
        $this->audit('identity_binding.update', (int) $binding->id, $identity, $request);
        return $this->success('已更新');
    }

    #[Permission('SandIAM 身份绑定停用', 'sand_iam:identity_binding:disable')]
    public function disable(Request $request): Response
    {
        $binding = $this->binding((int) $request->post('id', 0));
        $identity = $this->identity((int) $binding->identity_id);
        $binding->save(['status' => 2]);
        $this->audit('identity_binding.disable', (int) $binding->id, $identity, $request);
        return $this->success('已停用');
    }

    private function binding(int $bindingId): IdentityBinding
    {
        $binding = IdentityBinding::findOrEmpty($bindingId);
        if ($bindingId <= 0 || $binding->isEmpty()) { throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: identity binding', 400); }
        $this->identity((int) $binding->identity_id);
        return $binding;
    }

    private function identity(int $identityId): Identity
    {
        $identity = Identity::where('id', $identityId)->where('status', 1)->find();
        $application = $identity ? Application::find($identity->application_id) : null;
        if (!$identity || !$application) { throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: identity', 400); }
        $this->access()->assertOrganization((int) $application->organization_id);
        return $identity;
    }

    private function access(): AdminOrganizationAccess { return new AdminOrganizationAccess($this->adminId ?? 0, is_array($this->adminInfo ?? null) ? $this->adminInfo : null); }
    private function audit(string $action, int $resourceId, Identity $identity, Request $request): void { $application = Application::find($identity->application_id); (new AuditWriter())->write('admin', (string) ($this->adminId ?? 0), $application ? (int) $application->organization_id : null, (int) $identity->application_id, $action, 'identity_binding', $resourceId, 'succeeded', (string) $request->header('X-Request-Id', bin2hex(random_bytes(12)))); }
}
