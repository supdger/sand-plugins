<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

use support\think\Model;

final class AuditLog extends Model
{
    protected $table = 'sand_iam_audit_log';
    protected $pk = 'id';
    protected $json = ['context'];
    protected $jsonAssoc = true;
    protected $autoWriteTimestamp = false;
}
