<?php

declare(strict_types=1);

namespace plugin\sandadmin\exception {
    final class ApiException extends \RuntimeException {}
}

namespace {
    require_once dirname(__DIR__) . '/app/developer/OpenApiImportDocument.php';

    use plugin\SandIam\app\developer\OpenApiImportDocument;
    use plugin\sandadmin\exception\ApiException;

    function openApiImportAssert(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, $message . PHP_EOL);
            exit(1);
        }
    }

    $document = [
        'openapi' => '3.1.0',
        'info' => ['title' => 'Work items', 'version' => '1.0.0'],
        'paths' => [
            '/work-items/{id}/' => [
                'get' => [
                    'summary' => '查看工作项',
                    'description' => '读取一条工作项。',
                    'x-sand-iam' => ['riskLevel' => 'low'],
                ],
            ],
            '/work-items' => [
                'get' => [
                    'summary' => '工作项列表',
                    'x-sand-iam-risk-level' => 'medium',
                ],
                'post' => [
                    'summary' => '创建工作项',
                    'x-sand-iam' => ['risk_level' => 'high'],
                ],
            ],
        ],
    ];
    $operations = OpenApiImportDocument::operations($document);
    openApiImportAssert(count($operations) === 3, 'OpenAPI operation count is wrong');
    openApiImportAssert($operations[0]['operation_key'] === 'GET /work-items', 'operations are not canonical and sorted');
    openApiImportAssert($operations[0]['operation'] === 'list', 'collection GET was not mapped to list');
    openApiImportAssert($operations[1]['operation'] === 'read' && $operations[1]['route_template'] === '/work-items/{id}', 'item GET was not normalized to read');
    openApiImportAssert($operations[2]['operation'] === 'create' && $operations[2]['risk_level'] === 'high', 'POST metadata was lost');

    foreach ([
        array_replace($document, ['openapi' => '2.0']),
        array_replace($document, ['paths' => []]),
        array_replace_recursive($document, ['paths' => ['/work-items' => ['get' => ['x-sand-iam-risk-level' => 'low', 'summary' => '']]]]),
        array_replace_recursive($document, ['paths' => ['/work-items' => ['get' => ['x-sand-iam-risk-level' => 'unknown']]]]),
        array_replace($document, ['paths' => ['/unsafe?query=1' => ['get' => ['summary' => 'Unsafe', 'x-sand-iam-risk-level' => 'low']]]]),
    ] as $invalid) {
        try {
            OpenApiImportDocument::operations($invalid);
            openApiImportAssert(false, 'invalid OpenAPI document was accepted');
        } catch (ApiException $exception) {
            openApiImportAssert(str_starts_with($exception->getMessage(), 'SAND_IAM_OPENAPI_IMPORT_INVALID'), 'OpenAPI import error code is unstable');
        }
    }

    echo "OpenAPI import document non-PG checks passed\n";
}
