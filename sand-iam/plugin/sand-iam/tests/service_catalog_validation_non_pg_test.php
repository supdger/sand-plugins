<?php

declare(strict_types=1);

namespace plugin\sandadmin\exception {
    final class ApiException extends \RuntimeException {}
}

namespace {
    require_once dirname(__DIR__) . '/app/runtime/ServiceCatalog.php';

    use plugin\SandIam\app\runtime\ServiceCatalog;
    use plugin\sandadmin\exception\ApiException;

    $catalog = new ServiceCatalog();
    $method = new ReflectionMethod($catalog, 'declaration');
    $method->setAccessible(true);

    $valid = $method->invoke($catalog, ['code' => ' sand_ai ', 'name' => ' SandAI AI 能力 '], [
        ' sand_ai.model.read ' => ' 读取可用模型 ',
        'sand_ai.chat.complete' => '提交 AI 对话任务',
    ]);
    if ($valid !== [
        'sand_ai',
        'SandAI AI 能力',
        [
            'sand_ai.model.read' => '读取可用模型',
            'sand_ai.chat.complete' => '提交 AI 对话任务',
        ],
    ]) {
        fwrite(STDERR, "service catalog normalization failed\n");
        exit(1);
    }

    $invalid = [
        [['code' => 'Sand AI', 'name' => 'SandAI'], ['sand_ai.model.read' => '读取模型']],
        [['code' => 'sand_ai', 'name' => ''], ['sand_ai.model.read' => '读取模型']],
        [['code' => 'sand_ai', 'name' => str_repeat('名', 129)], ['sand_ai.model.read' => '读取模型']],
        [['code' => 'sand_ai', 'name' => 'SandAI'], []],
        [['code' => 'sand_ai', 'name' => 'SandAI'], ['BAD ACTION' => '读取模型']],
        [['code' => 'sand_ai', 'name' => 'SandAI'], ['sand_ai.model.read' => '']],
        [['code' => 'sand_ai', 'name' => 'SandAI'], [' sand_ai.model.read' => '读取模型', 'sand_ai.model.read ' => '重复动作']],
    ];

    foreach ($invalid as [$service, $actions]) {
        try {
            $method->invoke($catalog, $service, $actions);
            fwrite(STDERR, "invalid service catalog declaration was accepted\n");
            exit(1);
        } catch (ReflectionException $exception) {
            throw $exception;
        } catch (ApiException $exception) {
            if ($exception->getCode() !== 400 || !str_starts_with($exception->getMessage(), 'SAND_IAM_SERVICE_CATALOG_INVALID:')) {
                fwrite(STDERR, "invalid declaration returned an unstable error\n");
                exit(1);
            }
        }
    }

    echo 'service catalog validation non-PG checks passed' . PHP_EOL;
}
