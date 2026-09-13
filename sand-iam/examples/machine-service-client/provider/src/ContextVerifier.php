<?php
declare(strict_types=1);
namespace Sand\Iam\Example\ProviderB;
interface ContextVerifier
{
    /** @return array<string,mixed> */
    public function verify(string $context, string $requestId): array;
}
