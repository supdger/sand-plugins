<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class SyncResource extends AbstractSandIamModel
{
    protected $table = 'sand_iam_sync_resource';
    protected $hidden = ['source_key_hash', 'encrypted_snapshot'];
}
