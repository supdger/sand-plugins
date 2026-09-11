<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class ApplicationExperience extends AbstractSandIamModel
{
    protected $table = 'sand_iam_application_experience';
    protected $json = ['login_methods', 'registration_fields'];
    protected $jsonAssoc = true;
}
