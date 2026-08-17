<?php
declare(strict_types=1);

namespace plugin\sandworkflow\app\service;

use plugin\sandworkflow\app\contract\ActorRef;
use plugin\sandworkflow\app\contract\WorkflowStartContext;
use plugin\sandworkflow\app\logic\InstanceLogic;

final class WorkflowRuntimeService
{
    public function start(
        array $input,
        WorkflowStartContext $context,
        ?OrganizationParticipantMaterializer $organizationMaterializer = null
    ): array {
        return (new InstanceLogic())->startForContext(
            $input,
            $context,
            $organizationMaterializer
        );
    }

    public function approve(
        string $taskId,
        ActorRef $actor,
        array $scope,
        string $comment = '',
        string $requestId = '',
        array $formValue = []
    ): array
    {
        return (new InstanceLogic())->approveForActor(
            $taskId,
            $actor,
            $scope,
            $comment,
            $requestId,
            $formValue
        );
    }

    public function comment(array $input, ActorRef $actor, array $scope): array
    {
        return (new InstanceLogic())->commentForActor($input, $actor, $scope);
    }

    public function transfer(array $input, ActorRef $actor, array $scope): array
    {
        return (new InstanceLogic())->transferForActor($input, $actor, $scope);
    }

    public function addSign(array $input, ActorRef $actor, array $scope): array
    {
        return (new InstanceLogic())->addSignForActor($input, $actor, $scope);
    }

    public function delSign(array $input, ActorRef $actor, array $scope): array
    {
        return (new InstanceLogic())->delSignForActor($input, $actor, $scope);
    }

    public function backNodeList(string $taskId, ActorRef $actor, array $scope): array
    {
        return (new InstanceLogic())->backNodeListForActor($taskId, $actor, $scope);
    }

    public function back(array $input, ActorRef $actor, array $scope): array
    {
        return (new InstanceLogic())->backForActor($input, $actor, $scope);
    }

    public function copyQuery(ActorRef $actor, array $scope): mixed
    {
        return (new InstanceLogic())->copyQueryForActor($actor, $scope);
    }

    public function detail(
        string $instanceId,
        ActorRef $actor,
        array $scope,
        bool $monitoring = false
    ): array
    {
        return (new InstanceLogic())->detailForActor($instanceId, $actor, $scope, $monitoring);
    }
}
