<?php

declare(strict_types=1);

namespace plugin\sandadmin\exception {
    final class ApiException extends \RuntimeException {}
}

namespace {
    require_once dirname(__DIR__) . '/app/developer/RouteSyncManifest.php';

    use plugin\SandIam\app\developer\RouteSyncManifest;
    use plugin\sandadmin\exception\ApiException;

    function routeSyncAssert(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, $message . PHP_EOL);
            exit(1);
        }
    }

    $manifest = [
        'format' => RouteSyncManifest::FORMAT,
        'organization_code' => 'sand',
        'application_code' => 'lawyer',
        'environment_code' => 'production',
        'routes' => [
            ['method' => 'get', 'path' => '/api/law/v1/health'],
            ['method' => 'get', 'path' => '/api/law/v1/cases/{id}/', 'sand_iam' => ['api_code' => 'legal.case.read']],
            ['method' => 'post', 'route_template' => '/api/law/v1/cases', 'sand_iam' => ['api_code' => 'legal.case.create', 'api_version' => 'v2']],
        ],
    ];
    $normalized = RouteSyncManifest::normalize($manifest);
    routeSyncAssert($normalized['ignored_count'] === 1, 'unmarked Webman route was not ignored');
    routeSyncAssert($normalized['routes'][0]['method'] === 'GET' && $normalized['routes'][0]['route_template'] === '/api/law/v1/cases/{id}', 'method or route template was not normalized');
    routeSyncAssert($normalized['routes'][0]['api_version'] === 'v1' && $normalized['routes'][1]['api_version'] === 'v2', 'default or explicit API version was lost');
    routeSyncAssert(RouteSyncManifest::requireNormalized($normalized) === $normalized, 'normalized route manifest did not preserve its internal contract');
    routeSyncAssert(RouteSyncManifest::requireNormalized(RouteSyncManifest::requireNormalized($normalized)) === $normalized, 'repeated internal route-manifest validation changed the normalized result');

    $invalidNormalized = $normalized;
    $invalidNormalized['routes'][0]['sand_iam'] = ['api_code' => 'legal.case.read'];
    try {
        RouteSyncManifest::requireNormalized($invalidNormalized);
        routeSyncAssert(false, 'internal route sync path accepted a raw sand_iam declaration');
    } catch (ApiException $exception) {
        routeSyncAssert(str_starts_with($exception->getMessage(), 'SAND_IAM_ROUTE_SYNC_MANIFEST_INVALID'), 'internal route sync rejection code is not stable');
    }

    foreach ([
        array_replace($manifest, ['format' => 'unknown']),
        array_replace($manifest, ['environment_code' => 'x']),
        array_replace($manifest, ['routes' => [[
            'method' => 'GET', 'path' => '/api/law/v1/cases/{id}', 'sand_iam' => ['api_code' => 'legal.case.read'],
        ], [
            'method' => 'GET', 'path' => '/api/law/v1/cases/{id}/', 'sand_iam' => ['api_code' => 'legal.case.update'],
        ]]]),
        array_replace($manifest, ['routes' => [['method' => 'GET', 'path' => '/admin/users']]]),
    ] as $invalid) {
        try {
            RouteSyncManifest::normalize($invalid);
            routeSyncAssert(false, 'invalid route sync manifest was accepted');
        } catch (ApiException $exception) {
            routeSyncAssert(str_starts_with($exception->getMessage(), 'SAND_IAM_ROUTE_SYNC_'), 'route sync manifest error code is not stable');
        }
    }

    echo "route sync manifest non-PG checks passed\n";
}
