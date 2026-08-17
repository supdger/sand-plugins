<?php
declare(strict_types=1);

namespace plugin\sandworkflow\app\service;

use plugin\sandworkflow\app\contract\ActorRef;
use plugin\sandworkflow\app\logic\InstanceLogic;

final class WorkflowQueryService
{
    public function pending(ActorRef $actor, array $scope): mixed
    {
        return (new InstanceLogic())->pendingForActor($actor, $scope);
    }
}
