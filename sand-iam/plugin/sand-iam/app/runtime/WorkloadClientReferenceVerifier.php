<?php

declare(strict_types=1);

namespace plugin\SandIam\app\runtime;

use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\Environment;
use plugin\SandIam\app\model\Organization;
use plugin\SandIam\app\model\WorkloadClient;
use plugin\sandadmin\exception\ApiException;

/** Read-only reference facts for services that act before a business resource exists. */
final class WorkloadClientReferenceVerifier
{
    /** @return array{organization_id:int,application_id:int,environment_id:int,workload_client_id:int,status:int} */
    public function verifyForService(int $workloadClientId): array
    {
        if ($workloadClientId < 1) self::unavailable();
        $client = WorkloadClient::where('id', $workloadClientId)
            ->where('status', 1)->whereNull('delete_time')->find();
        if ($client === null) self::unavailable();
        $environment = Environment::where('id', (int) $client->environment_id)
            ->where('status', 1)->whereNull('delete_time')->find();
        if ($environment === null) self::unavailable();
        $application = Application::where('id', (int) $environment->application_id)
            ->where('status', 1)->whereNull('delete_time')->find();
        if ($application === null) self::unavailable();
        $organization = Organization::where('id', (int) $application->organization_id)
            ->where('status', 1)->whereNull('delete_time')->find();
        if ($organization === null) self::unavailable();
        return [
            'organization_id' => (int) $organization->id,
            'application_id' => (int) $application->id,
            'environment_id' => (int) $environment->id,
            'workload_client_id' => (int) $client->id,
            'status' => 1,
        ];
    }

    private static function unavailable(): never
    {
        throw new ApiException('SAND_IAM_INVOCATION_FACTS_UNVERIFIED: workload client reference is unavailable', 403);
    }
}
