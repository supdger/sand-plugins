<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use support\Request;

final class RequestId
{
    private const ATTRIBUTE = 'sand_iam.request_id';

    /** @var \WeakMap<Request, string>|null */
    private static ?\WeakMap $fallback = null;

    public static function fromRequest(Request $request): string
    {
        return self::fromRequestCached($request);
    }

    /**
     * Normalize the correlation identifier exactly once for a request.
     *
     * Webman requests expose mutable attributes in supported runtimes. The
     * WeakMap fallback keeps the same invariant for lightweight request
     * doubles and older framework versions without adding a dynamic property.
     */
    public static function fromRequestCached(Request $request): string
    {
        $cached = self::attribute($request);
        if (is_string($cached) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{7,95}$/', $cached) === 1) {
            return $cached;
        }

        $requestId = self::normalize((string) $request->header('X-Request-Id', ''));
        if (method_exists($request, 'setAttribute')) {
            $request->setAttribute(self::ATTRIBUTE, $requestId);
        }
        self::$fallback ??= new \WeakMap();
        self::$fallback[$request] = $requestId;

        return $requestId;
    }

    public static function normalize(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{7,95}$/', $value) === 1) {
            return $value;
        }

        return 'req_' . bin2hex(random_bytes(16));
    }

    private static function attribute(Request $request): mixed
    {
        if (method_exists($request, 'getAttribute')) {
            return $request->getAttribute(self::ATTRIBUTE);
        }

        return self::$fallback[$request] ?? null;
    }
}
