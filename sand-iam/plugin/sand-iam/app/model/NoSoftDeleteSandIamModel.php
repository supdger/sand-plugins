<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model;

/**
 * Tables in this group deliberately have no delete_time column.  The host
 * model enables soft-delete globally, so these records must opt out before
 * any ORM lookup can be issued.
 */
abstract class NoSoftDeleteSandIamModel extends AbstractSandIamModel
{
    protected $deleteTime = false;
}
