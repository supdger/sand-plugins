<?php

declare(strict_types=1);

namespace plugin\SandIam\app\runtime;

/**
 * Host-owned resource resolver. Implementations load the named business
 * resource from server-side storage; request payloads must never supply the
 * returned facts directly.
 */
interface ServiceInvocationFactResolver
{
    /**
     * @return array{
     *     organization_id:int,
     *     application_id:int,
     *     environment_id:int,
     *     workload_client_id:int,
     *     data_class:?string,
     *     resource_type:string,
     *     resource_ref:string
     * }
     */
    public function resolve(string $serviceCode, string $actionCode, string $resourceKey): array;
}
