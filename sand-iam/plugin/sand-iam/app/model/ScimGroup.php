<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class ScimGroup extends AbstractSandIamModel
{
    protected $table = 'sand_iam_scim_group';
    protected $json = ['source_attributes'];
    protected $jsonAssoc = true;
}
