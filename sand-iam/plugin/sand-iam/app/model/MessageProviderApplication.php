<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class MessageProviderApplication extends AbstractSandIamModel
{
    protected $table = 'sand_iam_message_provider_application';
    protected $json = ['purposes', 'template_codes'];
    protected $jsonAssoc = true;
}
