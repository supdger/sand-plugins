<?php

declare(strict_types=1);

namespace plugin\SandIam\app\runtime;

use plugin\SandIam\app\developer\ApplicationBusinessActionCatalog;
use plugin\SandIam\app\model\ApiResource;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\Identity;
use plugin\SandIam\app\service\HumanAuthService;
use plugin\SandIam\app\service\OAuthOidcService;
use plugin\SandIam\app\service\AuditWriter;
use plugin\SandIam\app\service\RequestId;
use plugin\sandadmin\exception\ApiException;

final class ApplicationAuthorizationService
{
    public function __construct(
        private readonly ApiGovernanceService $governance = new ApiGovernanceService(),
        private readonly PolicyAuthorizer $authorizer = new PolicyAuthorizer(),
        private readonly AuditWriter $auditWriter = new AuditWriter(),
    ) {}

    /** @param array<string,mixed> $attributes @return array<string,mixed> */
    public function decide(
        string $accessToken,
        string $organizationCode,
        string $applicationCode,
        string $apiCode,
        string $apiVersion,
        array $attributes,
        string $requestId,
    ): array {
        $requestId = RequestId::normalize($requestId);
        try {
            [$application, $identity, $api] = $this->principalAndApi(
                $accessToken,
                $organizationCode,
                $applicationCode,
                $apiCode,
                $apiVersion,
            );
            return $this->decision($application, $identity, $api, $attributes, $requestId);
        } catch (ApiException $exception) {
            $this->auditResolutionFailure($accessToken, $requestId, $apiCode, $exception);
            throw $exception;
        }
    }

    /** @param array<string,mixed> $attributes @return array<string,mixed> */
    public function decideRoute(
        string $accessToken,
        string $organizationCode,
        string $applicationCode,
        string $method,
        string $routeTemplate,
        array $attributes,
        string $requestId,
    ): array {
        $requestId = RequestId::normalize($requestId);
        try {
            if (str_starts_with(trim($accessToken), 'siam_at_')) {
                [$application, $identity] = $this->localPrincipal($accessToken, $organizationCode, $applicationCode);
                $api = $this->governance->resolveRoute((int) $application->id, $method, $routeTemplate);
            } else {
                $application = $this->governance->applicationByCode($organizationCode, $applicationCode);
                $api = $this->governance->resolveRoute((int) $application->id, $method, $routeTemplate);
                $identity = $this->oauthPrincipal($accessToken, $application, $api);
            }
            return $this->decision($application, $identity, $api, $attributes, $requestId);
        } catch (ApiException $exception) {
            $this->auditResolutionFailure($accessToken, $requestId, strtoupper($method) . ' ' . $routeTemplate, $exception);
            throw $exception;
        }
    }

    /** @param array<string,mixed> $scope @param array<string,mixed> $attributes */
    public function assertScope(
        int $applicationId,
        int $identityId,
        string $resourceCode,
        string $operation,
        array $scope,
        array $attributes,
        string $requestId,
    ): void {
        $requestId = RequestId::normalize($requestId);
        $this->authorizer->assertScope(
            $applicationId,
            $identityId,
            $resourceCode,
            $operation,
            $scope,
            $attributes,
            $requestId,
        );
    }

    /**
     * Build the mandatory second-stage guard for a route that has loaded its
     * business entity. The normalized request ID is retained so scope audits
     * remain associated with the route-level authorization decision.
     *
     * @param array<string,mixed> $decision
     * @param callable(object):array<string,mixed> $attributeResolver
     */
    public function entityScopeGuard(array $decision, string $mode, callable $attributeResolver): EntityScopeGuard
    {
        if (!in_array($mode, ['entity', 'collection'], true)
            || !is_int($decision['application_id'] ?? null)
            || !is_int($decision['identity_id'] ?? null)
            || !is_string($decision['resource_code'] ?? null)
            || !is_string($decision['operation'] ?? null)
            || !is_array($decision['scope'] ?? null)
            || !is_string($decision['request_id'] ?? null)) {
            throw new ApiException('SAND_IAM_ENTITY_SCOPE_DECISION_INVALID: 路由授权结果不能用于实体数据范围复核', 500);
        }
        return new EntityScopeGuard(
            $mode,
            \Closure::fromCallable($attributeResolver),
            function (array $attributes) use ($decision): void {
                $this->assertScope(
                    $decision['application_id'],
                    $decision['identity_id'],
                    $decision['resource_code'],
                    $decision['operation'],
                    $decision['scope'],
                    $attributes,
                    $decision['request_id'],
                );
            },
        );
    }

    /** @return array{0:Application,1:Identity,2:ApiResource} */
    private function principalAndApi(
        string $accessToken,
        string $organizationCode,
        string $applicationCode,
        string $apiCode,
        string $apiVersion,
    ): array {
        if (str_starts_with(trim($accessToken), 'siam_at_')) {
            [$application, $identity] = $this->localPrincipal($accessToken, $organizationCode, $applicationCode);
            $api = $this->governance->apiByCode((int) $application->id, $apiCode, $apiVersion);
            return [$application, $identity, $api];
        }

        $application = $this->governance->applicationByCode($organizationCode, $applicationCode);
        $api = $this->governance->apiByCode((int) $application->id, $apiCode, $apiVersion);
        $identity = $this->oauthPrincipal($accessToken, $application, $api);
        return [$application, $identity, $api];
    }

    /** @return array{0:Application,1:Identity} */
    private function localPrincipal(string $accessToken, string $organizationCode, string $applicationCode): array
    {
        $accessToken = trim($accessToken);
        if ($accessToken === '' || !str_starts_with($accessToken, 'siam_at_')) {
            throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
        }
        [$application, $identity] = (new HumanAuthService())->authenticatedPrincipal($accessToken);
        if ($organizationCode !== '' || $applicationCode !== '') {
            $expected = $this->governance->applicationByCode($organizationCode, $applicationCode);
            if ((int) $expected->id !== (int) $application->id) {
                throw new ApiException('SAND_IAM_APPLICATION_MISMATCH', 403);
            }
        }
        return [$application, $identity];
    }

    private function oauthPrincipal(string $accessToken, Application $application, ApiResource $api): Identity
    {
        $requiredScopes = (string) ($api->required_scope ?? '') === '' ? [] : [(string) $api->required_scope];
        $claims = (new OAuthOidcService())->verifyAccessTokenForAudience(
            trim($accessToken),
            (string) $api->audience,
            $requiredScopes,
        );
        if ((int) ($claims['application_id'] ?? 0) !== (int) $application->id || (int) ($claims['identity_id'] ?? 0) <= 0) {
            throw new ApiException('SAND_IAM_OAUTH_TOKEN_INVALID', 401);
        }
        $identity = Identity::where('id', (int) $claims['identity_id'])
            ->where('application_id', (int) $application->id)
            ->where('status', 1)
            ->find();
        if ($identity === null) {
            throw new ApiException('SAND_IAM_OAUTH_TOKEN_INVALID', 401);
        }
        return $identity;
    }

    /** @param array<string,mixed> $attributes @return array<string,mixed> */
    private function decision(
        Application $application,
        Identity $identity,
        ApiResource $api,
        array $attributes,
        string $requestId,
    ): array {
        // Runtime remains compatible with historical free-string records only
        // until they are claimed. A declared-but-disabled action is always a
        // fail-closed authorization denial, including a route-bound API.
        $actionDeclarationState = (new ApplicationBusinessActionCatalog())->assertEnabled(
            (int) $application->id,
            (string) $api->action,
            false,
        );
        $resource = \plugin\SandIam\app\model\Resource::where('id', (int) $api->resource_id)
            ->where('application_id', (int) $application->id)
            ->where('status', 1)
            ->find();
        if ($resource === null) {
            throw new ApiException('SAND_IAM_API_NOT_REGISTERED: 绑定的业务资源不可用', 403);
        }
        $decision = $this->authorizer->authorize(
            (int) $application->id,
            (int) $identity->id,
            (string) $resource->code,
            (string) $api->action,
            (string) $api->operation,
            $attributes,
            $requestId,
        );
        return $decision + [
            'application_id' => (int) $application->id,
            'identity_id' => (int) $identity->id,
            'api_code' => (string) $api->code,
            'api_version' => (string) $api->api_version,
            'resource_code' => (string) $resource->code,
            'action' => (string) $api->action,
            'operation' => (string) $api->operation,
            'risk_level' => (string) $api->risk_level,
            'action_declaration_state' => $actionDeclarationState,
            'request_id' => $requestId,
        ];
    }

    private function auditResolutionFailure(
        string $accessToken,
        string $requestId,
        string $target,
        ApiException $exception,
    ): void {
        $message = $exception->getMessage();
        preg_match('/^(SAND_IAM_[A-Z0-9_]+)/', $message, $matches);
        try {
            $this->auditWriter->write(
                'access_token',
                substr(hash('sha256', $accessToken), 0, 32),
                null,
                null,
                'authorize.resolve',
                'api_resource',
                null,
                'denied',
                $requestId !== '' ? substr($requestId, 0, 96) : bin2hex(random_bytes(16)),
                ['code' => $matches[1] ?? 'SAND_IAM_AUTHORIZATION_FAILED', 'target' => mb_substr($target, 0, 255)],
            );
        } catch (\Throwable) {
            // Authorization must remain fail-closed even if audit storage is unavailable.
        }
    }
}
