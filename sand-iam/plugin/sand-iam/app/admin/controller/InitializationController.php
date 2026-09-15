<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\AdminOrganizationAccess;
use plugin\SandIam\app\model\InitializationDraft;
use plugin\SandIam\app\model\InitializationRun;
use plugin\SandIam\app\service\InitializationService;
use plugin\SandIam\app\service\RequestId;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class InitializationController extends BaseController
{
    #[Permission('SandIAM 初始化包导出', 'sand_iam:initialization:export')]
    public function export(Request $request): Response
    {
        $applicationId = (int) $request->get('application_id', 0);
        $this->access($request)->assertApplication($applicationId);
        return $this->success((new InitializationService())->export($applicationId, trim((string) $request->get('package_code', ''))));
    }

    #[Permission('SandIAM 初始化预检', 'sand_iam:initialization:preview')]
    public function preview(Request $request): Response
    {
        $preview = (new InitializationService())->preview($this->manifest($request));
        $this->access($request)->assertOrganization((int) $preview['organization_id']);
        if ($preview['application_id'] !== null) $this->access($request)->assertApplication((int) $preview['application_id']);
        unset($preview['manifest']);
        return $this->success($preview);
    }

    #[Permission('SandIAM 初始化应用', 'sand_iam:initialization:apply')]
    public function apply(Request $request): Response
    {
        $service = new InitializationService();
        $manifest = $this->manifest($request);
        $preview = $service->preview($manifest);
        $this->access($request)->assertOrganization((int) $preview['organization_id']);
        if ($preview['application_id'] !== null) $this->access($request)->assertApplication((int) $preview['application_id']);
        return $this->success($service->apply($manifest, trim((string) $request->post('preview_hash', '')), $this->adminId($request), $this->requestId($request)), '初始化配置已应用');
    }

    #[Permission('SandIAM 初始化回滚', 'sand_iam:initialization:rollback')]
    public function rollback(Request $request): Response
    {
        $run = $this->run((int) $request->post('id', 0), $request);
        return $this->success((new InitializationService())->rollback((int) $run->id, trim((string) $request->post('confirmation', '')), $this->adminId($request), $this->requestId($request)), '初始化配置已回滚');
    }

    #[Permission('SandIAM 初始化草稿保存', 'sand_iam:initialization:save')]
    public function save(Request $request): Response
    {
        $service = new InitializationService();
        $manifest = $this->manifest($request);
        $preview = $service->preview($manifest);
        $this->access($request)->assertOrganization((int) $preview['organization_id']);
        if ($preview['application_id'] !== null) $this->access($request)->assertApplication((int) $preview['application_id']);
        return $this->success($service->saveDraft($manifest, $this->adminId($request), $this->requestId($request)), '初始化草稿已保存');
    }

    #[Permission('SandIAM 初始化草稿更新', 'sand_iam:initialization:update')]
    public function update(Request $request): Response
    {
        $draft = $this->draft((int) $request->post('id', 0), $request);
        return $this->success((new InitializationService())->updateDraft((int) $draft->id, $this->revision($request), $this->manifest($request), $this->adminId($request), $this->requestId($request)), '初始化草稿已更新');
    }

    #[Permission('SandIAM 初始化草稿停用', 'sand_iam:initialization:disable')]
    public function disable(Request $request): Response
    {
        $draft = $this->draft((int) $request->post('id', 0), $request);
        return $this->success((new InitializationService())->disableDraft((int) $draft->id, $this->revision($request), $this->adminId($request), $this->requestId($request)), '初始化草稿已停用');
    }

    #[Permission('SandIAM 初始化记录列表', 'sand_iam:initialization:index')]
    public function index(Request $request): Response
    {
        $query = InitializationRun::order('id', 'desc');
        $access = $this->access($request);
        if (!$access->isSuperAdmin()) {
            $ids = $access->organizationIds();
            if ($ids === []) $query->whereRaw('1 = 0'); else $query->whereIn('organization_id', $ids);
        }
        $organizationId = (int) $request->get('organization_id', 0);
        if ($organizationId > 0) { $access->assertOrganization($organizationId); $query->where('organization_id', $organizationId); }
        $applicationId = (int) $request->get('application_id', 0);
        if ($applicationId > 0) { $access->assertApplication($applicationId); $query->where('application_id', $applicationId); }
        return $this->success($query->field('id,organization_id,application_id,package_code,package_hash,preview_hash,state,applied_by,applied_time,rollback_by,rollback_time,request_id')->paginate(['page' => max(1, (int) $request->get('page', 1)), 'list_rows' => min(100, max(1, (int) $request->get('limit', 20)))])->toArray());
    }

    #[Permission('SandIAM 初始化记录读取', 'sand_iam:initialization:read')]
    public function read(Request $request): Response
    {
        $run = $this->run((int) $request->get('id', 0), $request);
        $data = $run->toArray();
        unset($data['manifest']);
        return $this->success($data);
    }

    /** Existing run responses remain unchanged; this is the explicit draft list route. */
    #[Permission('SandIAM 初始化草稿列表', 'sand_iam:initialization:index')]
    public function draftIndex(Request $request): Response
    {
        $query = InitializationDraft::order('id', 'desc');
        $access = $this->access($request);
        if (!$access->isSuperAdmin()) {
            $ids = $access->organizationIds();
            if ($ids === []) $query->whereRaw('1 = 0'); else $query->whereIn('organization_id', $ids);
        }
        $organizationId = (int) $request->get('organization_id', 0);
        if ($organizationId > 0) { $access->assertOrganization($organizationId); $query->where('organization_id', $organizationId); }
        $applicationId = (int) $request->get('application_id', 0);
        if ($applicationId > 0) { $access->assertApplication($applicationId); $query->where('application_id', $applicationId); }
        return $this->success($query->field('id,organization_id,application_id,package_code,manifest_hash,revision,status,created_by,updated_by,disabled_by,disabled_time,create_time,update_time')->paginate(['page' => max(1, (int) $request->get('page', 1)), 'list_rows' => min(100, max(1, (int) $request->get('limit', 20)))])->toArray());
    }

    /** Draft content is secret-free, but is readable only after the same scope check. */
    #[Permission('SandIAM 初始化草稿读取', 'sand_iam:initialization:read')]
    public function draftRead(Request $request): Response
    {
        return $this->success($this->draft((int) $request->get('id', 0), $request)->toArray());
    }

    /** @return array<string,mixed> */
    private function manifest(Request $request): array
    {
        $manifest = $request->post('manifest', null);
        if (!is_array($manifest)) throw new ApiException('SAND_IAM_INITIALIZATION_INVALID: manifest 必须是 JSON 对象', 400);
        return $manifest;
    }

    private function run(int $id, Request $request): InitializationRun
    {
        $run = $id > 0 ? InitializationRun::find($id) : null;
        if ($run === null) throw new ApiException('SAND_IAM_INITIALIZATION_RUN_NOT_FOUND', 404);
        $this->access($request)->assertOrganization((int) $run->organization_id);
        if ($run->application_id !== null) $this->access($request)->assertApplication((int) $run->application_id);
        return $run;
    }

    private function draft(int $id, Request $request): InitializationDraft
    {
        $draft = $id > 0 ? InitializationDraft::find($id) : null;
        if ($draft === null) throw new ApiException('SAND_IAM_INITIALIZATION_DRAFT_NOT_FOUND', 404);
        $this->access($request)->assertOrganization((int) $draft->organization_id);
        if ($draft->application_id !== null) $this->access($request)->assertApplication((int) $draft->application_id);
        return $draft;
    }

    private function revision(Request $request): int
    {
        $revision = $request->post('revision', 0);
        if (!is_int($revision) && !(is_string($revision) && preg_match('/^[0-9]+$/D', $revision) === 1)) throw new ApiException('SAND_IAM_INITIALIZATION_DRAFT_REVISION_INVALID', 400);
        $revision = filter_var(is_string($revision) ? ltrim($revision, '0') : $revision, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($revision === false) throw new ApiException('SAND_IAM_INITIALIZATION_DRAFT_REVISION_INVALID', 400);
        return $revision;
    }

    private function adminId(Request $request): int
    {
        $token = $request->header('check_admin', []);
        return is_array($token) ? (int) ($token['id'] ?? 0) : 0;
    }

    private function access(Request $request): AdminOrganizationAccess
    {
        $token = $request->header('check_admin', []);
        return new AdminOrganizationAccess($this->adminId($request), is_array($token) ? $token : null);
    }

    private function requestId(Request $request): string
    {
        return RequestId::fromRequestCached($request);
    }
}
