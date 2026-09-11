<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class WebhookEndpoint extends AbstractSandIamModel
{
    protected $table = 'sand_iam_webhook_endpoint';
    protected $json = ['event_types'];
    protected $jsonAssoc = true;
    protected $hidden = ['encrypted_secret'];
}
