<?php
declare(strict_types=1);

namespace plugin\sandworkflow\app\service;

use plugin\sandworkflow\app\logic\DefinitionLogic;

final class WorkflowDefinitionService
{
    public function publish(array $definition): mixed
    {
        return (new DefinitionLogic())->publish($definition);
    }
}
