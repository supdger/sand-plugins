<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class OAuthClient extends AbstractSandIamModel
{
    protected $table = 'sand_iam_oauth_client';
    protected $json = ['redirect_uris', 'post_logout_redirect_uris', 'allowed_scopes', 'allowed_audiences'];
    protected $jsonAssoc = true;
    protected $hidden = ['secret_hash'];
}
