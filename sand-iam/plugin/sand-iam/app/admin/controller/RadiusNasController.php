<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\ApplicationResourceController;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\RadiusNas;
use plugin\SandIam\app\radius\RadiusNetwork;
use plugin\SandIam\app\service\RadiusSecretCipher;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class RadiusNasController extends ApplicationResourceController
{
    protected string $modelClass = RadiusNas::class;
    protected array $writeFields = ['application_id', 'name', 'source_cidr', 'accounting_enabled', 'status'];
    protected array $requiredFields = ['application_id', 'name', 'source_cidr'];
    protected string $resourceType = 'radius_nas';

    #[Permission('SandIAM RADIUS 网络设备列表', 'sand_iam:radius_nas:index')] public function index(Request $request): Response { return parent::index($request); }
    #[Permission('SandIAM RADIUS 网络设备读取', 'sand_iam:radius_nas:read')] public function read(Request $request): Response { return parent::read($request); }
    #[Permission('SandIAM RADIUS 网络设备保存', 'sand_iam:radius_nas:save')] public function save(Request $request): Response { return parent::save($request); }
    #[Permission('SandIAM RADIUS 网络设备更新', 'sand_iam:radius_nas:update')] public function update(Request $request): Response { return parent::update($request); }
    #[Permission('SandIAM RADIUS 网络设备停用', 'sand_iam:radius_nas:disable')] public function disable(Request $request): Response { return parent::disable($request); }

    #[Permission('SandIAM RADIUS 网络设备配置密钥', 'sand_iam:radius_nas:configure')]
    public function configure(Request $request): Response
    {
        $nas = $this->find($request);
        if ((int) $nas->status !== 1) throw new ApiException('SAND_IAM_RADIUS_NAS_DISABLED', 409);
        $secret = (string) $request->post('shared_secret', '');
        $nas->save(['encrypted_shared_secret' => (new RadiusSecretCipher())->encrypt($secret), 'secret_version' => (string) config('plugin.sand-iam.app.radius_encryption_key_version', 'v1')]);
        $this->audit('secret_rotate', (int) $nas->id, $request);
        return $this->success(['id' => (int) $nas->id, 'secret_configured' => true], '共享密钥已保存，旧密钥立即失效且不会再次展示。');
    }

    protected function assertReferences(array $payload, ?object $existing = null): void
    {
        $applicationId = (int) ($payload['application_id'] ?? $existing?->application_id ?? 0);
        if (!Application::where('id', $applicationId)->where('status', 1)->find()) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 所属接入应用不存在或已停用', 400);
        $cidr = trim((string) ($payload['source_cidr'] ?? $existing?->source_cidr ?? ''));
        if (!RadiusNetwork::validCidr($cidr)) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 来源网段必须是规范的 IPv4 或 IPv6 CIDR，例如 10.20.0.0/24', 400);
        foreach (RadiusNas::where('status', 1)->select()->all() as $candidate) {
            if ($existing !== null && (int) $candidate->id === (int) $existing->id) continue;
            $candidateCidr = (string) $candidate->source_cidr;
            $newAddress = explode('/', $cidr, 2)[0];
            $candidateAddress = explode('/', $candidateCidr, 2)[0];
            if (RadiusNetwork::contains($candidateCidr, $newAddress) || RadiusNetwork::contains($cidr, $candidateAddress)) throw new ApiException('SAND_IAM_RADIUS_NAS_NETWORK_OVERLAP: 来源网段与已有设备重叠，无法可靠选择共享密钥', 409);
        }
    }

    /** @return array<string,mixed> */
    protected function payload(Request $request, bool $updating): array
    {
        $payload = parent::payload($request, $updating);
        if (array_key_exists('name', $payload)) $payload['name'] = trim((string) $payload['name']);
        if (array_key_exists('source_cidr', $payload)) $payload['source_cidr'] = trim((string) $payload['source_cidr']);
        if (array_key_exists('accounting_enabled', $payload)) $payload['accounting_enabled'] = filter_var($payload['accounting_enabled'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
        return $payload;
    }
}
