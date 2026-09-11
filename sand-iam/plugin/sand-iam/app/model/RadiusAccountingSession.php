<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class RadiusAccountingSession extends AbstractSandIamModel
{
    protected $table = 'sand_iam_radius_accounting_session';
    protected $hidden = ['session_reference', 'user_reference'];
}
