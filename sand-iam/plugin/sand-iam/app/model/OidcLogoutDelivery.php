<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class OidcLogoutDelivery extends AbstractSandIamModel
{
    protected $table = 'sand_iam_oidc_logout_delivery';
    protected $hidden = ['encrypted_logout_token'];
}
