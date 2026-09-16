<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\runtime\RouteBindingSynchronizer;
use plugin\sandadmin\exception\ApiException;

final class RouteManifestService
{
    public function __construct(
        private readonly RouteBindingSynchronizer $synchronizer = new RouteBindingSynchronizer(),
        private readonly IdempotencyService $operations = new IdempotencyService(),
    ) {}

    /** @param array<string,mixed> $manifest @return array<string,mixed> */
    public function preview(array $manifest, bool $disableMissing, string $operationId = ''): array
    {
        return $this->synchronizer->synchronize(
            $manifest,
            apply: false,
            disableMissing: $disableMissing,
            operationId: $operationId,
        );
    }

    /** @param array<string,mixed> $manifest @return array<string,mixed> */
    public function apply(
        array $manifest,
        bool $disableMissing,
        string $expectedPreviewHash,
        int $adminId,
        string $requestId,
    ): array {
        if ($adminId <= 0) {
            throw new ApiException('SAND_IAM_ADMIN_REQUIRED: 管理员登录状态无效', 401);
        }
        $fingerprint = IdempotencyService::fingerprint([
            'manifest' => $manifest,
            'disable_missing' => $disableMissing,
            'preview_hash' => $expectedPreviewHash,
        ]);
        $execution = $this->operations->execute(
            'admin',
            (string) $adminId,
            'route_manifest.apply',
            $requestId,
            $fingerprint,
            'route_manifest',
            function () use ($manifest, $disableMissing, $expectedPreviewHash, $requestId): array {
                $result = $this->synchronizer->synchronize(
                    $manifest,
                    apply: true,
                    disableMissing: $disableMissing,
                    requestId: $requestId,
                    operationId: $requestId,
                    expectedPreviewHash: $expectedPreviewHash,
                );
                return ['resource_id' => (int) $result['application_id'], 'result' => $result];
            },
        );
        return $execution['result'] + ['replayed' => $execution['replayed']];
    }
}
