<?php

declare(strict_types=1);

namespace plugin\SandIam\app\kerberos;

use plugin\sandadmin\exception\ApiException;

final class UnavailableSpnegoVerifier implements SpnegoVerifier
{
    public function verify(string $token, array $config, array $context): array
    {
        throw new ApiException('SAND_IAM_KERBEROS_VERIFIER_UNAVAILABLE', 503);
    }
}
