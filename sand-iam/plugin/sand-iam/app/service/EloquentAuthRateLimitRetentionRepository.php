<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\model\AuthRateLimit;
use think\facade\Db;

final class EloquentAuthRateLimitRetentionRepository implements AuthRateLimitRetentionRepository
{
    public function purgeExpiredBefore(string $cutoff, int $limit): int
    {
        Db::startTrans();
        try {
            $ids = AuthRateLimit::where('window_start', '<=', $cutoff)
                ->order('window_start')
                ->order('id')
                ->limit($limit)
                ->lock(true)
                ->column('id');
            if ($ids === []) {
                Db::commit();
                return 0;
            }
            $deleted = Db::table('sand_iam_auth_rate_limit')
                ->whereIn('id', $ids)
                ->where('window_start', '<=', $cutoff)
                ->delete();
            Db::commit();
            return (int) $deleted;
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
    }
}
