<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

/**
 * Application-owned business action declarations.
 *
 * These are deliberately separate from sand_iam_service_action: a service
 * integration may expose technical operations, while this catalog is the
 * vocabulary used by an application's resources, APIs and policies.
 */
final class ApplicationBusinessAction extends AbstractSandIamModel
{
    protected $table = 'sand_iam_application_business_action';
}
