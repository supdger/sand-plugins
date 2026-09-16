<?php
declare(strict_types=1);
namespace Sand\Iam\Example\ProviderB;
use Sand\Iam\Sdk\SandIamClient;
use Sand\Iam\Sdk\SandIamException;
final class SandIamContextVerifier implements ContextVerifier
{
    private readonly \Closure $verifyContext;
    public function __construct(ProviderConfig $config, ?\Closure $verifyContext = null)
    {
        $client = new SandIamClient($config->iamBaseUrl, $config->organizationCode, $config->applicationCode);
        $this->verifyContext = $verifyContext ?? static fn(string $context, string $requestId): array =>
            $client->verifyContext($context, $config->serviceCode, $config->audience, [$config->action], null, $requestId . '-verify');
    }
    /** @return array<string,mixed> */
    public function verify(string $context, string $requestId): array
    {
        if ($context === '' || strlen($context) > 16384) throw new ProviderException('PROVIDER_B_CONTEXT_INVALID', 401);
        try {
            return ($this->verifyContext)($context, $requestId);
        } catch (SandIamException $e) {
            if (in_array($e->httpStatus, [401, 403], true)) throw new ProviderException('PROVIDER_B_CONTEXT_DENIED', $e->httpStatus);
            throw new ProviderException('PROVIDER_B_CONTEXT_UNAVAILABLE', 503);
        } catch (\Throwable) {
            throw new ProviderException('PROVIDER_B_CONTEXT_UNAVAILABLE', 503);
        }
    }
}
