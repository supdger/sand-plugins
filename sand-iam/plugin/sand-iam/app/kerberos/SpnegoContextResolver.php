<?php

declare(strict_types=1);

namespace plugin\SandIam\app\kerberos;

use support\Request;

interface SpnegoContextResolver
{
    /**
     * Resolve transport facts only from a trusted TLS terminator/server API.
     * Implementations must ignore client-supplied identity and binding headers.
     *
     * @return array{channel_binding:string,remote_ip:string}
     */
    public function resolve(Request $request): array;
}
