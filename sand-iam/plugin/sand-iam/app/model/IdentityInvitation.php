<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class IdentityInvitation extends AbstractSandIamModel
{
    protected $table = 'sand_iam_identity_invitation';
    protected $json = ['initial_group_ids'];
    protected $jsonAssoc = true;
    protected $hidden = ['target_hash', 'encrypted_target', 'token_hash', 'encrypted_delivery_token'];
}
