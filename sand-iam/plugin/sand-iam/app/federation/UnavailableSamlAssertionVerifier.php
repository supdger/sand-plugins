<?php

declare(strict_types=1);

namespace plugin\SandIam\app\federation;

use plugin\sandadmin\exception\ApiException;

/**
 * XML parsing is not XMLDSig verification.  This gate keeps SAML disabled
 * unless the deployment installs and wires an audited verifier.
 */
final class UnavailableSamlAssertionVerifier implements SamlAssertionVerifier
{
    public function start(array $config, string $acs, string $relayState): array
    {
        throw new ApiException('SAND_IAM_SAML_VERIFIER_UNAVAILABLE', 503);
    }

    public function verify(string $samlResponse, array $config, string $expectedRequestId, string $expectedRecipient): array
    {
        throw new ApiException('SAND_IAM_SAML_VERIFIER_UNAVAILABLE', 503);
    }
}
