<?php

declare(strict_types=1);

namespace plugin\sandadmin\exception {
    final class ApiException extends \RuntimeException {}
}

namespace think\facade {
    final class Db
    {
        public static int $starts = 0;
        public static int $commits = 0;
        public static int $rollbacks = 0;
        public static function startTrans(): void { self::$starts++; }
        public static function commit(): void { self::$commits++; }
        public static function rollback(): void { self::$rollbacks++; }
    }
}

namespace plugin\SandIam\app\model {
    final class MemoryQuery
    {
        /** @var array<string,scalar> */
        private array $where = [];
        /** @param class-string<Service|ServiceAction> $model */
        public function __construct(private string $model) {}
        public function where(string $field, mixed $value): self { $this->where[$field] = $value; return $this; }
        public function lock(bool $lock): self { return $this; }
        public function find(): Service|ServiceAction|null
        {
            foreach ($this->model::$rows as $row) {
                foreach ($this->where as $field => $value) {
                    if (($row->{$field} ?? null) !== $value) continue 2;
                }
                return $row;
            }
            return null;
        }
    }

    final class Service
    {
        /** @var array<int,self> */ public static array $rows = [];
        public static int $nextId = 1;
        public static bool $raceOnce = false;
        public int $id;
        public string $code;
        public string $name;
        public int $status;
        public static function where(string $field, mixed $value): MemoryQuery { return (new MemoryQuery(self::class))->where($field, $value); }
        /** @param array{code:string,name:string,status:int} $payload */
        public function save(array $payload): void
        {
            $this->id = self::$nextId++;
            foreach ($payload as $field => $value) $this->{$field} = $value;
            self::$rows[$this->id] = $this;
            if (self::$raceOnce) {
                self::$raceOnce = false;
                throw new \RuntimeException('SQLSTATE[23505]: unique constraint');
            }
        }
        public static function reset(): void { self::$rows = []; self::$nextId = 1; self::$raceOnce = false; }
    }

    final class ServiceAction
    {
        /** @var array<int,self> */ public static array $rows = [];
        public static int $nextId = 1;
        public int $id;
        public int $service_id;
        public string $code;
        public string $name;
        public int $status;
        public static function where(string $field, mixed $value): MemoryQuery { return (new MemoryQuery(self::class))->where($field, $value); }
        /** @param array{service_id:int,code:string,name:string,status:int} $payload */
        public function save(array $payload): void
        {
            $this->id = self::$nextId++;
            foreach ($payload as $field => $value) $this->{$field} = $value;
            self::$rows[$this->id] = $this;
        }
        public static function reset(): void { self::$rows = []; self::$nextId = 1; }
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/runtime/ServiceCatalog.php';

    use plugin\SandIam\app\model\Service;
    use plugin\SandIam\app\model\ServiceAction;
    use plugin\SandIam\app\runtime\ServiceCatalog;
    use plugin\sandadmin\exception\ApiException;
    use think\facade\Db;

    $reset = static function (): void {
        Service::reset();
        ServiceAction::reset();
        Db::$starts = Db::$commits = Db::$rollbacks = 0;
    };
    $service = ['code' => 'sand_ai', 'name' => 'SandAI AI 能力'];
    $actions = ['sand_ai.model.read' => '读取可用模型', 'sand_ai.chat.complete' => '提交 AI 对话任务'];
    $catalog = new ServiceCatalog();

    $reset();
    $catalog->registerServiceActions($service, $actions);
    $catalog->registerServiceActions(['code' => 'sand_ai', 'name' => '不会覆盖原名称'], [
        'sand_ai.model.read' => '不会覆盖动作名称',
        'sand_ai.chat.complete' => '提交 AI 对话任务',
    ]);
    if (count(Service::$rows) !== 1 || count(ServiceAction::$rows) !== 2
        || Service::$rows[1]->name !== 'SandAI AI 能力'
        || ServiceAction::$rows[1]->name !== '读取可用模型'
        || Db::$commits !== 2) {
        fwrite(STDERR, "service catalog is not idempotent or overwrote an existing declaration\n");
        exit(1);
    }

    Service::$rows[1]->status = 2;
    try {
        $catalog->registerServiceActions($service, $actions);
        fwrite(STDERR, "disabled service was silently restored\n");
        exit(1);
    } catch (ApiException $exception) {
        if ($exception->getCode() !== 409 || Service::$rows[1]->status !== 2) throw $exception;
    }

    $reset();
    $catalog->registerServiceActions($service, $actions);
    ServiceAction::$rows[1]->status = 2;
    try {
        $catalog->registerServiceActions($service, $actions);
        fwrite(STDERR, "disabled service action was silently restored\n");
        exit(1);
    } catch (ApiException $exception) {
        if ($exception->getCode() !== 409 || ServiceAction::$rows[1]->status !== 2) throw $exception;
    }

    $reset();
    Service::$raceOnce = true;
    $catalog->registerServiceActions($service, $actions);
    if (count(Service::$rows) !== 1 || count(ServiceAction::$rows) !== 2 || Db::$rollbacks !== 1 || Db::$commits !== 1) {
        fwrite(STDERR, "service catalog did not converge after a concurrent unique conflict\n");
        exit(1);
    }

    echo 'service catalog behavior non-PG checks passed' . PHP_EOL;
}
