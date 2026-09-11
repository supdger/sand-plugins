<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class ScimResource extends AbstractSandIamModel
{
    protected $table = 'sand_iam_scim_resource';
    protected $json = ['source_attributes'];
    protected $jsonAssoc = true;
}
