<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

use think\model\concern\SoftDelete;

final class ScimGroupMember extends AbstractSandIamModel
{
    use SoftDelete;

    protected $table = 'sand_iam_scim_group_member';
}
