<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\model\SecurityOperation;
use think\facade\Db;

final class EloquentSecurityOperationRetentionRepository implements SecurityOperationRetentionRepository
{
    public function purgeSucceededBefore(string $cutoff, int $limit): int
    {
        Db::startTrans();
        try {
            $ids = SecurityOperation::where('state', 'succeeded')
                ->where('update_time', '<=', $cutoff)
                ->order('id')
                ->limit($limit)
                ->lock(true)
                ->column('id');
            if ($ids === []) {
                Db::commit();
                return 0;
            }
            $deleted = SecurityOperation::whereIn('id', $ids)
                ->where('state', 'succeeded')
                ->where('update_time', '<=', $cutoff)
                ->delete();
            Db::commit();
            return (int) $deleted;
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
    }
}
