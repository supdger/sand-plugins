<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class OAuthRegistrationToken extends AbstractSandIamModel
{
    protected $table = 'sand_iam_oauth_registration_token';
    protected $json = ['allowed_redirect_hosts', 'allowed_scopes'];
    protected $jsonAssoc = true;
    protected $hidden = ['token_hash', 'last_used_ip_hash'];
}
