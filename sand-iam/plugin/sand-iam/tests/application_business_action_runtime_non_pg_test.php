<?php

declare(strict_types=1);

namespace plugin\sandadmin\exception {
    final class ApiException extends \RuntimeException {}
}

namespace plugin\SandIam\app\model {
    final class ApplicationBusinessActionQuery
    {
        /** @var array<string,mixed> */ private array $where = [];
        public function where(string $field, mixed $value): self { $this->where[$field] = $value; return $this; }
        public function find(): ?object
        {
            foreach (ApplicationBusinessAction::$rows as $row) {
                foreach ($this->where as $field => $value) if (($row->{$field} ?? null) !== $value) continue 2;
                return $row;
            }
            return null;
        }
    }
    final class ApplicationBusinessAction
    {
        /** @var list<object> */ public static array $rows = [];
        public static function where(string $field, mixed $value): ApplicationBusinessActionQuery
        {
            return (new ApplicationBusinessActionQuery())->where($field, $value);
        }
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/developer/ApplicationBusinessActionCatalog.php';

    use plugin\SandIam\app\developer\ApplicationBusinessActionCatalog;
    use plugin\SandIam\app\model\ApplicationBusinessAction;
    use plugin\sandadmin\exception\ApiException;

    function t28Runtime(bool $condition, string $message): void
    {
        if (!$condition) { fwrite(STDERR, $message . PHP_EOL); exit(1); }
    }

    $enabled = (object) ['application_id' => 11, 'code' => 'matter.export', 'status' => 1];
    ApplicationBusinessAction::$rows = [$enabled];
    $catalog = new ApplicationBusinessActionCatalog();
    t28Runtime($catalog->assertEnabled(11, 'matter.export', true) === 'declared', 'enabled declared action was not accepted');

    $enabled->status = 2;
    try {
        $catalog->assertEnabled(11, 'matter.export', false);
        t28Runtime(false, 'disabled action did not fail closed');
    } catch (ApiException $exception) {
        t28Runtime(str_starts_with($exception->getMessage(), 'SAND_IAM_APPLICATION_ACTION_DISABLED'), 'disabled action used an unstable failure code');
    }

    ApplicationBusinessAction::$rows = [];
    t28Runtime($catalog->assertEnabled(11, 'matter.export', false) === 'pending_claim', 'historical action did not enter compatibility pending claim state');
    try {
        $catalog->assertEnabled(11, 'matter.export', true);
        t28Runtime(false, 'strict new/change validation accepted undeclared action');
    } catch (ApiException $exception) {
        t28Runtime(str_starts_with($exception->getMessage(), 'SAND_IAM_APPLICATION_ACTION_UNDECLARED'), 'undeclared action used an unstable failure code');
    }

    echo "application business action runtime non-PG checks passed\n";
}
