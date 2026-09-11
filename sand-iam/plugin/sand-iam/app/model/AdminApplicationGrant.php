<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

use plugin\sandadmin\app\model\system\SystemUser;

final class AdminApplicationGrant extends AbstractSandIamModel
{
    protected $table = 'sand_iam_admin_application_grant';
    protected $append = ['admin_user_name'];

    public function getAdminUserNameAttr(): string
    {
        $user = SystemUser::find((int) $this->admin_user_id);
        if ($user === null) return '已删除的后台管理员';
        $name = trim((string) ($user->realname ?: $user->username));
        return $name !== '' ? $name : '未命名后台管理员';
    }
}
