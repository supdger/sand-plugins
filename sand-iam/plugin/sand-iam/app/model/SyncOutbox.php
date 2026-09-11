<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class SyncOutbox extends AbstractSandIamModel
{
    protected $table = 'sand_iam_sync_outbox';
    protected $hidden = ['encrypted_payload'];
}
