<?php

declare(strict_types=1);

namespace plugin\SandIam\app\webhook;

interface WebhookHttpAdapter
{
    /** @param array<string,string> $headers @return array{status:int,body:string} */
    public function post(string $url, array $headers, string $body, int $timeoutSeconds): array;
}
