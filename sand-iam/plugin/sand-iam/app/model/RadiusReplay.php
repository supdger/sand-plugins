<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class RadiusReplay extends AbstractSandIamModel
{
    protected $table = 'sand_iam_radius_replay';
    protected $hidden = ['request_fingerprint'];
}
