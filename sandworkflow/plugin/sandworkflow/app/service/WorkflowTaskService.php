<?php
declare(strict_types=1);

namespace plugin\sandworkflow\app\service;

use plugin\sandworkflow\app\contract\ActorRef;

final class WorkflowTaskService
{
    public function pending(ActorRef $actor, array $scope): mixed
    {
        return (new WorkflowQueryService())->pending($actor, $scope);
    }
}
