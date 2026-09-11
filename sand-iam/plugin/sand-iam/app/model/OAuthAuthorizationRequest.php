<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class OAuthAuthorizationRequest extends AbstractSandIamModel
{
    protected $table = 'sand_iam_oauth_authorization_request';
    protected $hidden = ['request_hash', 'csrf_hash', 'encrypted_payload'];
}
