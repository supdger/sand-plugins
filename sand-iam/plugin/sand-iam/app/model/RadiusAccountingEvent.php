<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class RadiusAccountingEvent extends AbstractSandIamModel
{
    protected $table = 'sand_iam_radius_accounting_event';
    protected $hidden = ['session_reference', 'user_reference', 'request_fingerprint'];
}
