<?php
declare(strict_types=1);

namespace plugin\sandworkflow\app\service;

use InvalidArgumentException;
use plugin\sandworkflow\app\contract\OrganizationParticipantProvider;
use plugin\sandworkflow\app\contract\OrganizationResolutionRequest;
use support\Container;
use UnexpectedValueException;

final class ConfiguredOrganizationParticipantProvider implements OrganizationParticipantProvider
{
    private ?OrganizationParticipantProvider $provider = null;

    public function __construct(private readonly string $className)
    {
        if ($className === '') {
            throw new InvalidArgumentException('组织关系Provider类名不能为空');
        }
    }

    public function supports(string $accountSpace): bool
    {
        return $this->provider()->supports($accountSpace);
    }

    public function resolve(OrganizationResolutionRequest $request): array
    {
        return $this->provider()->resolve($request);
    }

    private function provider(): OrganizationParticipantProvider
    {
        if ($this->provider !== null) {
            return $this->provider;
        }
        $container = Container::instance();
        $provider = $container === null
            ? new $this->className()
            : $container->make($this->className);
        if (!$provider instanceof OrganizationParticipantProvider) {
            throw new UnexpectedValueException('配置类未实现组织关系Provider协议');
        }
        return $this->provider = $provider;
    }
}
