<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class IdentityProvider extends AbstractSandIamModel
{
    protected $table = 'sand_iam_identity_provider';
    protected $json = ['attribute_mapping'];
    protected $jsonAssoc = true;
    protected $hidden = ['encrypted_config'];
}
