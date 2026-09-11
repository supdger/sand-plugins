<?php

declare(strict_types=1);

namespace plugin\SandIam\app\runtime;

use plugin\sandadmin\exception\ApiException;

/** Immutable host-composition registry; resolver names are not PHP class names. */
final readonly class ServiceInvocationFactResolverRegistry
{
    /** @var array<string,ServiceInvocationFactResolver> */
    private array $resolvers;

    /** @param array<string,ServiceInvocationFactResolver> $resolvers */
    public function __construct(array $resolvers = [])
    {
        $normalized = [];
        foreach ($resolvers as $code => $resolver) {
            if (!is_string($code) || !$resolver instanceof ServiceInvocationFactResolver || preg_match('/^[a-z0-9][a-z0-9._-]{1,63}$/', $code) !== 1 || isset($normalized[$code])) {
                throw new ApiException('SAND_IAM_INVOCATION_FACTS_UNVERIFIED: 服务端事实解析器注册无效', 500);
            }
            $normalized[$code] = $resolver;
        }
        $this->resolvers = $normalized;
    }

    /**
     * @return array{
     *     organization_id:int,
     *     application_id:int,
     *     environment_id:int,
     *     workload_client_id:int,
     *     data_class:?string,
     *     resource_type:string,
     *     resource_ref:string
     * }
     */
    public function resolve(string $resolverCode, string $serviceCode, string $actionCode, string $resourceKey): array
    {
        $resolver = $this->resolvers[$resolverCode] ?? null;
        if ($resolver === null || $resourceKey === '' || strlen($resourceKey) > 191 || preg_match('/[\x00-\x1F\x7F]/', $resourceKey) === 1) {
            throw new ApiException('SAND_IAM_INVOCATION_FACTS_UNVERIFIED: 未找到可信服务端资源解析器或资源键无效', 403);
        }
        try {
            $facts = $resolver->resolve($serviceCode, $actionCode, $resourceKey);
        } catch (\Throwable) {
            throw new ApiException('SAND_IAM_INVOCATION_FACTS_UNVERIFIED: 服务端资源解析失败', 403);
        }
        if (
            !array_key_exists('data_class', $facts)
            || !isset(
                $facts['organization_id'],
                $facts['application_id'],
                $facts['environment_id'],
                $facts['workload_client_id'],
                $facts['resource_type'],
                $facts['resource_ref'],
            )
        ) {
            throw new ApiException('SAND_IAM_INVOCATION_FACTS_UNVERIFIED: 服务端资源事实不完整', 403);
        }
        return $facts;
    }
}
