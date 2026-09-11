<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\support;

use plugin\SandIam\app\model\Environment;

abstract class EnvironmentResourceController extends AdminResourceController
{
    protected function scopeIndexToOrganizations(object $query): void
    {
        if ($this->access()->isSuperAdmin()) return;
        $applicationIds = $this->access()->applicationIds();
        if ($applicationIds === []) {
            $query->whereRaw('1 = 0');
            return;
        }
        $query->whereIn('environment_id', Environment::whereIn('application_id', $applicationIds)->column('id'));
    }

    protected function assertModelAccess(object $model): void
    {
        $environment = Environment::find((int) ($model->environment_id ?? 0));
        $this->access()->assertApplication((int) ($environment?->application_id ?? 0));
    }

    protected function assertPayloadAccess(array $payload, ?object $existing = null): void
    {
        $environmentId = (int) ($payload['environment_id'] ?? $existing?->environment_id ?? 0);
        $environment = Environment::find($environmentId);
        $this->access()->assertApplication((int) ($environment?->application_id ?? 0));
    }
}
