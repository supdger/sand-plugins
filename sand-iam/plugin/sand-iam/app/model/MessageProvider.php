<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class MessageProvider extends AbstractSandIamModel
{
    protected $table = 'sand_iam_message_provider';
    protected $hidden = ['encrypted_config'];
}
