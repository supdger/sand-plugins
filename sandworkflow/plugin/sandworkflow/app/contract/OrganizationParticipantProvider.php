<?php
declare(strict_types=1);

namespace plugin\sandworkflow\app\contract;

interface OrganizationParticipantProvider
{
    /**
     * 一个 Provider 可以支持一个或多个账户域。
     */
    public function supports(string $accountSpace): bool;

    /** @return list<ActorRef> */
    public function resolve(OrganizationResolutionRequest $request): array;
}
