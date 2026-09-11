<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class SyncConnector extends AbstractSandIamModel
{
    protected $table = 'sand_iam_sync_connector';
    protected $json = ['authority_map'];
    protected $jsonAssoc = true;
    protected $hidden = ['encrypted_config', 'encrypted_cursor'];
}
