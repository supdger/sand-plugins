<?php

declare(strict_types=1);

namespace plugin\SandIam\app\kerberos;

interface SpnegoVerifier
{
    /**
     * The implementation must validate GSSAPI integrity, SPN, clock skew,
     * channel binding and replay before returning a principal.
     *
     * @param array<string,mixed> $config
     * @param array{channel_binding:string,remote_ip:string} $context
     * @return array{principal:string,service_principal:string,mutual_auth:bool,channel_binding:bool,replay_protected:bool,response_token?:string}
     */
    public function verify(string $token, array $config, array $context): array;
}
