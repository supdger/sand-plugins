<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

final class WebhookDelivery extends AbstractSandIamModel
{
    protected $table = 'sand_iam_webhook_delivery';
    protected $json = ['payload'];
    protected $jsonAssoc = true;
}
