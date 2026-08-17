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

    protected function organizationIdForModel(object $model): ?int
    {
        $application = Application::find($model->application_id);
        return $application ? (int) $application->organization_id : null;
    }

    protected function assertPayloadAccess(array $payload, ?object $existing = null): void
    {
        $applicationId = (int) ($payload['application_id'] ?? $existing?->application_id ?? 0);
        $application = Application::find($applicationId);
        $this->access()->assertOrganization($application ? (int) $application->organization_id : 0);
    }
}
