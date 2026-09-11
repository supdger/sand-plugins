<?php

declare(strict_types=1);

namespace plugin\SandIam\app\oidc;

interface OidcBackchannelHttpAdapter
{
    /** @return array{status:int,body:string} */
    public function postLogoutToken(string $url, string $body, int $timeoutSeconds): array;
}
