<?php

declare(strict_types=1);

namespace plugin\SandIam\app\federation;

interface SamlAssertionVerifier
{
    /** @param array<string,mixed> $config @return array{request_id:string,redirect_uri:string} */
    public function start(array $config, string $acs, string $relayState): array;

    /** @param array<string,mixed> $config @return array<string,mixed> verified claims only */
    public function verify(string $samlResponse, array $config, string $expectedRequestId, string $expectedRecipient): array;
}
