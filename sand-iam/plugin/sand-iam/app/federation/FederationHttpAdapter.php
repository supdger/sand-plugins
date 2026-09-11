<?php

declare(strict_types=1);

namespace plugin\SandIam\app\federation;

/**
 * Network boundary for external identity providers.  Tests inject a fake;
 * callers must never let provider responses reach logs unchanged.
 */
interface FederationHttpAdapter
{
    /** @return array<string,mixed> */
    public function json(string $method, string $url, array $headers = [], array $form = []): array;
}
