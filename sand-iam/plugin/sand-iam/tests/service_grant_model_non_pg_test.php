<?php

declare(strict_types=1);

namespace plugin\SandIam\app\model {
    abstract class AbstractSandIamModel {}
}

namespace {
    require_once dirname(__DIR__) . '/app/model/ServiceGrant.php';

    use plugin\SandIam\app\model\ServiceGrant;

    function serviceGrantModelAssert(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, $message . PHP_EOL);
            exit(1);
        }
    }

    $method = new ReflectionMethod(ServiceGrant::class, 'setQuotaPolicyAttr');
    $method->setAccessible(true);
    $grant = new ServiceGrant();
    $unlimited = $method->invoke($grant, []);
    serviceGrantModelAssert(is_object($unlimited) && get_object_vars($unlimited) === [], 'unlimited quota must persist as an empty JSON object');
    $limited = ['max_invocation_attempts' => 2, 'window_seconds' => 60];
    serviceGrantModelAssert($method->invoke($grant, $limited) === $limited, 'limited quota policy changed during persistence normalization');

    fwrite(STDOUT, "service grant model non-PG checks passed\n");
}
