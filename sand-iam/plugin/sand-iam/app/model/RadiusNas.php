<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class RadiusNas extends AbstractSandIamModel
{
    protected $table = 'sand_iam_radius_nas';
    protected $hidden = ['encrypted_shared_secret'];
    protected $append = ['secret_configured'];

    public function getSecretConfiguredAttr(): bool
    {
        return trim((string) ($this->getData('encrypted_shared_secret') ?? '')) !== '';
    }
}
