<?php
declare(strict_types=1);
namespace Sand\Iam\Example\ProviderB;
interface ProcessStore
{
    /** @param array<string,mixed> $claims @return array<string,mixed> */
    public function process(array $claims, string $documentId, string $idempotencyKey, string $requestId): array;
}
