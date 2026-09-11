<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class AuditArchive extends AbstractSandIamModel
{
    protected $table = 'sand_iam_audit_archive';
    protected $json = ['context'];
    protected $jsonAssoc = true;
}
