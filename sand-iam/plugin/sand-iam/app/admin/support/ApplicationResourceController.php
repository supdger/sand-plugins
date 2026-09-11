<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\support;

use plugin\SandIam\app\model\Application;

abstract class ApplicationResourceController extends AdminResourceController
{
    protected function applyOrganizationScope(object $query, array $organizationIds): void
    {
        $query->whereIn('application_id', Application::whereIn('organization_id', $organizationIds)->column('id'));
    }

    protected function scopeIndexToOrganizations(object $query): void
    {
        if ($this->access()->isSuperAdmin()) return;
        $applicationIds = $this->access()->applicationIds();
        if ($applicationIds === []) {
            $query->whereRaw('1 = 0');
            return;
        }
        $query->whereIn('application_id', $applicationIds);
    }

    protected function organizationIdForModel(object $model): ?int
    {
        $application = Application::find($model->application_id);
        return $application ? (int) $application->organization_id : null;
    }

    protected function assertPayloadAccess(array $payload, ?object $existing = null): void
    {
        $applicationId = (int) ($payload['application_id'] ?? $existing?->application_id ?? 0);
        $this->access()->assertApplication($applicationId);
    }

    protected function assertModelAccess(object $model): void
    {
        $this->access()->assertApplication((int) ($model->application_id ?? 0));
    }
}
