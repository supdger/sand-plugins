<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\AdminOrganizationAccess;
use plugin\SandIam\app\model\IdentityImportJob;
use plugin\SandIam\app\model\IdentityImportRow;
use plugin\SandIam\app\service\IdentityImportService;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class IdentityImportController extends BaseController
{
    #[Permission('SandIAM 用户导入预检', 'sand_iam:identity_import:preview')]
    public function preview(Request $request): Response
    {
        $applicationId = (int) $request->post('application_id', 0); $this->access($request)->assertApplication($applicationId); $file = $request->file('file');
        if ($file === null || !$file->isValid() || $file->getSize() <= 0 || $file->getSize() > 2_097_152) throw new ApiException('SAND_IAM_IMPORT_FILE_INVALID', 400);
        $csv = file_get_contents($file->getPathname()); if (!is_string($csv)) throw new ApiException('SAND_IAM_IMPORT_FILE_INVALID', 400);
        return $this->success((new IdentityImportService())->preview($applicationId, (string) $file->getUploadName(), $csv, (string) $request->post('mode', 'create'), $this->actor($request), $this->requestId($request)), '预检完成；尚未创建或修改任何应用用户')->withHeader('Cache-Control', 'no-store');
    }
    #[Permission('SandIAM 用户导入确认', 'sand_iam:identity_import:confirm')]
    public function confirm(Request $request): Response
    {
        $job = $this->job($request); return $this->success((new IdentityImportService())->confirm((int) $job->id, (int) $job->application_id, (string) $request->post('digest', ''), $this->actor($request), $this->requestId($request)), '导入任务已执行');
    }
    #[Permission('SandIAM 用户导入任务', 'sand_iam:identity_import:index')]
    public function index(Request $request): Response
    {
        $applicationId = (int) $request->input('application_id', 0); $this->access($request)->assertApplication($applicationId); $query = IdentityImportJob::where('application_id', $applicationId)->order('id', 'desc');
        return $this->success($query->paginate(['page' => max(1, (int) $request->input('page', 1)), 'list_rows' => min(100, max(1, (int) $request->input('limit', 20)))])->toArray());
    }
    #[Permission('SandIAM 用户导入报告', 'sand_iam:identity_import:read')]
    public function rows(Request $request): Response
    {
        $job = $this->job($request); $query = IdentityImportRow::where('import_job_id', (int) $job->id)->order('row_number', 'asc'); $state = (string) $request->input('state', ''); if ($state !== '') $query->where('state', $state);
        $result = $query->paginate(['page' => max(1, (int) $request->input('page', 1)), 'list_rows' => min(200, max(1, (int) $request->input('limit', 50)))])->toArray();
        $result['data'] = array_map(static fn (array $row): array => ['id' => (int) ($row['id'] ?? 0), 'row_number' => (int) ($row['row_number'] ?? 0), 'summary' => is_array($row['summary'] ?? null) ? $row['summary'] : [], 'validation_errors' => is_array($row['validation_errors'] ?? null) ? $row['validation_errors'] : [], 'state' => (string) ($row['state'] ?? ''), 'error_code' => $row['error_code'] ?? null], $result['data'] ?? []);
        return $this->success($result);
    }
    #[Permission('SandIAM 用户脱敏导出', 'sand_iam:identity_export:masked')]
    public function exportMasked(Request $request): Response { return $this->download($request, false); }
    #[Permission('SandIAM 用户敏感导出', 'sand_iam:identity_export:sensitive')]
    public function exportSensitive(Request $request): Response { return $this->download($request, true); }
    private function download(Request $request, bool $sensitive): Response
    {
        $applicationId = (int) $request->input('application_id', 0); $this->access($request)->assertApplication($applicationId); $csv = (new IdentityImportService())->export($applicationId, $sensitive, $this->actor($request), $this->requestId($request));
        return response($csv)->withHeader('Content-Type', 'text/csv; charset=utf-8')->withHeader('Content-Disposition', 'attachment; filename="sand-iam-users-' . date('Ymd-His') . '.csv"')->withHeader('Cache-Control', 'no-store');
    }
    private function job(Request $request): IdentityImportJob { $job = IdentityImportJob::find((int) $request->input('id', $request->post('id', 0))); if ($job === null) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 导入任务不存在', 404); $this->access($request)->assertApplication((int) $job->application_id); return $job; }
    private function access(Request $request): AdminOrganizationAccess { $token = $request->header('check_admin', []); return new AdminOrganizationAccess(is_array($token) ? (int) ($token['id'] ?? 0) : 0, is_array($token) ? $token : null); }
    private function actor(Request $request): string { $token = $request->header('check_admin', []); return (string) (is_array($token) ? ($token['id'] ?? 0) : 0); }
    private function requestId(Request $request): string { return substr((string) $request->header('X-Request-Id', ''), 0, 96); }
}
