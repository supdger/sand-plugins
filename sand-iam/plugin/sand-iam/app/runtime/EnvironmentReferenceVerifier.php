<?php

declare(strict_types=1);

namespace plugin\SandIam\app\runtime;

use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\Environment;
use plugin\sandadmin\exception\ApiException;

final class EnvironmentReferenceVerifier
{
    /** @return array{organization_id:int,application_id:int,environment_id:int,status:int} */
    public function verifyEnvironmentReference(int $applicationId, int $environmentId): array
    {
        $environment = Environment::where('id', $environmentId)
            ->where('application_id', $applicationId)
            ->where('status', 1)
            ->find();
        if ($environment === null) {
            throw new ApiException('SAND_IAM_SERVICE_ACTION_FORBIDDEN: environment reference is unavailable', 403);
        }
        $application = Application::where('id', $applicationId)->where('status', 1)->find();
        if ($application === null) {
            throw new ApiException('SAND_IAM_SERVICE_ACTION_FORBIDDEN: application reference is unavailable', 403);
        }
        return [
            'organization_id' => (int) $application->organization_id,
            'application_id' => (int) $application->id,
            'environment_id' => (int) $environment->id,
            'status' => (int) $environment->status,
        ];
    }

    /** @return array{organization_id:int,application_id:int,environment_id:int,status:int} */
    public function verifyEnvironmentReferenceForClient(int $environmentId): array
    {
        $environment = Environment::where('id', $environmentId)->where('status', 1)->find();
        if ($environment === null) {
            throw new ApiException('SAND_IAM_SERVICE_ACTION_FORBIDDEN: environment reference is unavailable', 403);
        }
        return $this->verifyEnvironmentReference((int) $environment->application_id, (int) $environment->id);
    }
}
