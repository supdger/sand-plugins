<?php
declare(strict_types=1);

namespace plugin\sandworkflow\app\model\concern;

use plugin\sandworkflow\app\support\NanoId;

trait UsesNanoId
{
    public static function onBeforeInsert($model): void
    {
        parent::onBeforeInsert($model);
        if (!$model->getAttr('id')) {
            $model->setAttr('id', NanoId::generate());
        }
    }
}
