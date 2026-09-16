<?php
declare(strict_types=1);
namespace Sand\Iam\Example\ProviderB;
final class ProviderApplication
{
    public function __construct(
        private readonly ContextVerifier $verifier,
        private readonly ProcessStore $store,
        private readonly string $serviceCode = ProviderProtocol::SERVICE_CODE,
        private readonly string $audience = ProviderProtocol::AUDIENCE,
        private readonly string $action = ProviderProtocol::ACTION,
    ) {
        ProviderProtocol::serviceCode($this->serviceCode);
        ProviderProtocol::audience($this->audience);
        ProviderProtocol::action($this->action);
    }
    /** @return array<string,mixed> */
    public function process(string $documentId, string $context, string $idempotencyKey, string $requestId): array
    {
        ProviderProtocol::documentId($documentId);
        ProviderProtocol::idempotencyKey($idempotencyKey);
        ProviderProtocol::requestId($requestId);
        // Verify before every persistence attempt, including idempotency replays.
        $claims = $this->verifier->verify($context, $requestId);
        $this->fixedClaims($claims);
        return $this->store->process($claims, $documentId, $idempotencyKey, $requestId);
    }
    /** @param array<string,mixed> $claims */
    private function fixedClaims(array $claims): void
    {
        $actions = $claims['actions'] ?? null;
        if (!is_string($claims['context_id'] ?? null) || $claims['context_id'] === ''
            || !is_int($claims['workload_client_id'] ?? null) || $claims['workload_client_id'] <= 0
            || !is_int($claims['organization_id'] ?? null) || $claims['organization_id'] <= 0
            || !is_string($claims['service_code'] ?? null) || !hash_equals($this->serviceCode, $claims['service_code'])
            || !is_string($claims['audience'] ?? null) || !hash_equals($this->audience, $claims['audience'])
            || !is_array($actions) || !array_is_list($actions) || $actions !== [$this->action]) {
            throw new ProviderException('PROVIDER_B_CONTEXT_CLAIMS_INVALID', 403);
        }
    }
}
