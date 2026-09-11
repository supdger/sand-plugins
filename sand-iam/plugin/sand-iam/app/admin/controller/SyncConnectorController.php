<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\AdminOrganizationAccess;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\SyncConnector;
use plugin\SandIam\app\model\SyncRun;
use plugin\SandIam\app\service\AuditWriter;
use plugin\SandIam\app\service\SyncConnectorService;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class SyncConnectorController extends BaseController
{
    #[Permission('SandIAM 同步连接列表', 'sand_iam:sync_connector:index')]
    public function index(Request $request): Response
    {
        $applicationId = (int) $request->input('application_id', 0); $this->access($request)->assertApplication($applicationId); $query = SyncConnector::where('application_id', $applicationId)->order('id', 'desc');
        $result = $query->paginate(['page' => max(1, (int) $request->input('page', 1)), 'list_rows' => min(100, max(1, (int) $request->input('limit', 20)))])->toArray(); $result['data'] = array_map(fn (array $item): array => $this->safe($item), $result['data'] ?? []); return $this->success($result);
    }
    #[Permission('SandIAM 同步连接读取', 'sand_iam:sync_connector:read')]
    public function read(Request $request): Response { return $this->success($this->safe($this->connector($request)->toArray())); }
    #[Permission('SandIAM 同步连接保存', 'sand_iam:sync_connector:save')]
    public function save(Request $request): Response
    {
        $applicationId = (int) $request->post('application_id', 0); $this->access($request)->assertApplication($applicationId); $application = Application::where('id', $applicationId)->where('status', 1)->find(); if ($application === null) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 所属接入应用不存在或已停用', 404);
        $code = trim((string) $request->post('code', '')); $name = trim((string) $request->post('name', '')); $driver = trim((string) $request->post('driver_code', '')); $direction = $this->direction((string) $request->post('direction', 'inbound'));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $code) || $name === '' || mb_strlen($name) > 128 || !preg_match('/^[a-z][a-z0-9_.-]{1,63}$/', $driver)) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 同步连接名称、系统代码或驱动代码无效', 400);
        try { $connector = SyncConnector::create(['organization_id' => (int) $application->organization_id, 'application_id' => $applicationId, 'code' => $code, 'name' => $name, 'direction' => $direction, 'driver_code' => $driver, 'authority_map' => [], 'conflict_policy' => 'manual', 'missing_protection_hours' => 24, 'disable_threshold_percent' => 20, 'config_version' => 0, 'status' => 1]); }
        catch (\Throwable $exception) { if (str_contains(strtolower($exception->getMessage()), 'unique')) throw new ApiException('SAND_IAM_SYNC_CONNECTOR_CONFLICT', 409); throw $exception; }
        $this->audit($connector, 'sync_connector.create', $request); return $this->success(['id' => (int) $connector->id], '同步连接已创建，请继续填写连接配置并测试');
    }
    #[Permission('SandIAM 同步连接更新', 'sand_iam:sync_connector:update')]
    public function update(Request $request): Response
    {
        $connector = $this->connector($request); $name = trim((string) $request->post('name', (string) $connector->name)); $hours = (int) $request->post('missing_protection_hours', (int) $connector->missing_protection_hours); $threshold = (int) $request->post('disable_threshold_percent', (int) $connector->disable_threshold_percent); $policy = (string) $request->post('conflict_policy', (string) $connector->conflict_policy);
        if ($name === '' || mb_strlen($name) > 128 || $hours < 1 || $hours > 720 || $threshold < 1 || $threshold > 100 || !in_array($policy, ['manual', 'reject', 'source_wins', 'local_wins'], true)) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 同步保护或冲突参数无效', 400);
        $connector->save(['name' => $name, 'missing_protection_hours' => $hours, 'disable_threshold_percent' => $threshold, 'conflict_policy' => $policy]); $this->audit($connector, 'sync_connector.update', $request); return $this->success('同步连接已更新');
    }
    #[Permission('SandIAM 同步连接停用', 'sand_iam:sync_connector:disable')]
    public function disable(Request $request): Response { $connector = $this->connector($request); if (SyncRun::where('sync_connector_id', (int) $connector->id)->where('state', 'running')->find()) throw new ApiException('SAND_IAM_SYNC_ALREADY_RUNNING', 409); $connector->save(['status' => 2]); $this->audit($connector, 'sync_connector.disable', $request); return $this->success('同步连接已停用'); }
    #[Permission('SandIAM 同步连接配置', 'sand_iam:sync_connector:configure')]
    public function configure(Request $request): Response { $connector = $this->connector($request); $config = $request->post('config', []); $map = $request->post('authority_map', []); if (!is_array($config) || !is_array($map)) throw new ApiException('SAND_IAM_SYNC_CONFIGURATION_INVALID', 400); (new SyncConnectorService())->configure($connector, $config, $map, $this->actor($request), $this->requestId($request)); return $this->success(['config_version' => (int) $connector->config_version], '连接配置已加密保存，之后不回显原值')->withHeader('Cache-Control', 'no-store'); }
    #[Permission('SandIAM 同步连接测试', 'sand_iam:sync_connector:test')]
    public function test(Request $request): Response { $connector = $this->connector($request); (new SyncConnectorService())->test($connector, $this->actor($request), $this->requestId($request)); return $this->success('连接测试成功'); }
    #[Permission('SandIAM 同步执行', 'sand_iam:sync_run:run')]
    public function run(Request $request): Response { $connector = $this->connector($request); return $this->success((new SyncConnectorService())->run((int) $connector->id, (int) $connector->application_id, $this->actor($request), $this->requestId($request)), '同步执行完成'); }
    #[Permission('SandIAM 同步记录', 'sand_iam:sync_run:index')]
    public function runs(Request $request): Response { $connector = $this->connector($request); return $this->success(SyncRun::where('sync_connector_id', (int) $connector->id)->order('id', 'desc')->paginate(['page' => max(1, (int) $request->input('page', 1)), 'list_rows' => min(100, max(1, (int) $request->input('limit', 20)))])->toArray()); }
    private function connector(Request $request): SyncConnector { $connector = SyncConnector::find((int) $request->input('id', $request->post('id', 0))); if ($connector === null) throw new ApiException('SAND_IAM_SYNC_CONNECTOR_NOT_FOUND', 404); $this->access($request)->assertApplication((int) $connector->application_id); return $connector; }
    /** @param array<string,mixed> $item @return array<string,mixed> */ private function safe(array $item): array { return ['id' => (int) ($item['id'] ?? 0), 'application_id' => (int) ($item['application_id'] ?? 0), 'code' => (string) ($item['code'] ?? ''), 'name' => (string) ($item['name'] ?? ''), 'direction' => (string) ($item['direction'] ?? ''), 'driver_code' => (string) ($item['driver_code'] ?? ''), 'conflict_policy' => (string) ($item['conflict_policy'] ?? ''), 'missing_protection_hours' => (int) ($item['missing_protection_hours'] ?? 0), 'disable_threshold_percent' => (int) ($item['disable_threshold_percent'] ?? 0), 'config_version' => (int) ($item['config_version'] ?? 0), 'config_configured' => !empty($item['encrypted_config']), 'cursor_configured' => !empty($item['encrypted_cursor']), 'last_sync_time' => $item['last_sync_time'] ?? null, 'status' => (int) ($item['status'] ?? 0)]; }
    private function direction(string $value): string { if (!in_array($value, ['inbound', 'outbound', 'bidirectional'], true)) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 同步方向无效', 400); return $value; }
    private function access(Request $request): AdminOrganizationAccess { $token = $request->header('check_admin', []); return new AdminOrganizationAccess(is_array($token) ? (int) ($token['id'] ?? 0) : 0, is_array($token) ? $token : null); }
    private function actor(Request $request): string { $token = $request->header('check_admin', []); return (string) (is_array($token) ? ($token['id'] ?? 0) : 0); }
    private function requestId(Request $request): string { return substr((string) $request->header('X-Request-Id', ''), 0, 96); }
    private function audit(SyncConnector $connector, string $action, Request $request): void { (new AuditWriter())->write('admin', $this->actor($request), (int) $connector->organization_id, (int) $connector->application_id, $action, 'sync_connector', (int) $connector->id, 'succeeded', $this->requestId($request) ?: bin2hex(random_bytes(16))); }
}
