<?php

declare(strict_types=1);

namespace plugin\SandIam\app\kerberos;

use plugin\sandadmin\exception\ApiException;
use support\Request;

final class UnavailableSpnegoContextResolver implements SpnegoContextResolver
{
    public function resolve(Request $request): array
    {
        throw new ApiException('SAND_IAM_KERBEROS_TRANSPORT_CONTEXT_UNAVAILABLE', 503);
    }
}
