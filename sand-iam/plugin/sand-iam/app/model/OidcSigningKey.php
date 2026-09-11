<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class OidcSigningKey extends AbstractSandIamModel
{
    protected $table = 'sand_iam_oidc_signing_key';
    protected $json = ['public_jwk'];
    protected $jsonAssoc = true;
    protected $hidden = ['encrypted_private_key', 'encryption_version'];

    protected $deleteTime = false;
}
