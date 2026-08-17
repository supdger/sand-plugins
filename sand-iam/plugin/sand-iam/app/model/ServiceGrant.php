<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class ServiceGrant extends AbstractSandIamModel
{
    protected $table = 'sand_iam_service_grant';

    protected $json = ['quota_policy', 'network_policy'];
}
