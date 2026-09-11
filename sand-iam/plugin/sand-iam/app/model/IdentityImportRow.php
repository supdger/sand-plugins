<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class IdentityImportRow extends AbstractSandIamModel
{
    protected $table = 'sand_iam_identity_import_row';
    protected $json = ['summary', 'validation_errors'];
    protected $jsonAssoc = true;
    protected $hidden = ['encrypted_payload'];
}
