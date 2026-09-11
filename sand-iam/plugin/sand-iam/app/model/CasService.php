<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class CasService extends AbstractSandIamModel
{
    protected $table = 'sand_iam_cas_service';
    protected $json = ['released_attributes'];
    protected $jsonAssoc = true;
}
