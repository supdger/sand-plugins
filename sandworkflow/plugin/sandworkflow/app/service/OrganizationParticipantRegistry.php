<?php
declare(strict_types=1);

namespace plugin\sandworkflow\app\service;

use DomainException;
use LogicException;
use plugin\sandworkflow\app\contract\ActorRef;
use plugin\sandworkflow\app\contract\OrganizationParticipantProvider;
use plugin\sandworkflow\app\contract\OrganizationResolutionRequest;
use Throwable;
use UnexpectedValueException;

final class OrganizationParticipantRegistry
{
    /** @var list<OrganizationParticipantProvider> */
    private array $providers;

    /** @param iterable<OrganizationParticipantProvider> $providers */
    public function __construct(iterable $providers)
    {
        $this->providers = [];
        foreach ($providers as $provider) {
            if (!$provider instanceof OrganizationParticipantProvider) {
                throw new UnexpectedValueException('组织关系Provider类型无效');
            }
            $this->providers[] = $provider;
        }
    }

    public function supports(string $accountSpace): bool
    {
        return count($this->matchingProviders($accountSpace)) === 1;
    }

    /** @return list<ActorRef> */
    public function resolve(OrganizationResolutionRequest $request): array
    {
        $providers = $this->matchingProviders($request->initiator->accountSpace);
        if ($providers === []) {
            throw new DomainException(
                '账户域未配置组织关系Provider：' . $request->initiator->accountSpace
            );
        }
        if (count($providers) > 1) {
            throw new LogicException(
                '账户域配置了多个组织关系Provider：' . $request->initiator->accountSpace
            );
        }

        try {
            $actors = $providers[0]->resolve($request);
        } catch (DomainException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new UnexpectedValueException(
                '组织关系Provider执行失败',
                0,
                $exception
            );
        }
        if ($actors === [] || count($actors) > 100) {
            throw new DomainException('组织关系Provider未返回有效处理人');
        }
        $unique = [];
        foreach ($actors as $actor) {
            if (!$actor instanceof ActorRef) {
                throw new UnexpectedValueException('组织关系Provider必须返回ActorRef');
            }
            $unique[$actor->accountSpace . ':' . $actor->accountId] = $actor;
        }
        if ($unique === [] || count($unique) > 25) {
            throw new DomainException('组织关系处理人数量必须为1至25人');
        }
        return array_values($unique);
    }

    /** @return list<OrganizationParticipantProvider> */
    private function matchingProviders(string $accountSpace): array
    {
        $matched = [];
        foreach ($this->providers as $provider) {
            try {
                if ($provider->supports($accountSpace)) {
                    $matched[] = $provider;
                }
            } catch (Throwable $exception) {
                throw new UnexpectedValueException(
                    '组织关系Provider能力检查失败',
                    0,
                    $exception
                );
            }
        }
        return $matched;
    }
}
