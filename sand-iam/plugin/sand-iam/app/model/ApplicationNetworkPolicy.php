<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class ApplicationNetworkPolicy extends AbstractSandIamModel
{
    protected $table = 'sand_iam_application_network_policy';
    protected $json = ['allow_cidrs', 'deny_cidrs'];
    protected $jsonAssoc = true;
}
