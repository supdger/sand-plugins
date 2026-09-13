<?php
declare(strict_types=1);
namespace Sand\Iam\Example\ProviderB;
final class ProviderProtocol
{
    public const SERVICE_CODE = 'provider-b-document';
    public const AUDIENCE = 'provider-b';
    public const ACTION = 'document.process';
    public static function documentId(string $value): void { self::assert($value, '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', 'PROVIDER_B_INVALID_DOCUMENT_ID'); }
    public static function idempotencyKey(string $value): void { self::assert($value, '/^[A-Za-z0-9][A-Za-z0-9._-]{7,95}$/', 'PROVIDER_B_INVALID_IDEMPOTENCY_KEY'); }
    public static function requestId(string $value): void { self::assert($value, '/^[A-Za-z0-9][A-Za-z0-9._-]{7,95}$/', 'PROVIDER_B_INVALID_REQUEST_ID'); }
    private static function assert(string $value, string $pattern, string $error): void
    {
        if (preg_match($pattern, $value) !== 1) throw new ProviderException($error, 400);
    }
}
