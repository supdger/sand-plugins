<?php
declare(strict_types=1);
namespace Sand\Iam\Example\ProviderB;
final class ProviderProtocol
{
    public const SERVICE_CODE = 'provider-b-document';
    public const AUDIENCE = 'provider-b';
    public const ACTION = 'document.process';
    public static function documentId(string $value): void { self::assert($value, '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', 'PROVIDER_B_INVALID_DOCUMENT_ID'); }
    public static function idempotencyKey(string $value): void { self::assert($value, '/^[A-Za-z0-9][A-Za-z0-9._-]{7,95}$/D', 'PROVIDER_B_INVALID_IDEMPOTENCY_KEY'); }
    public static function requestId(string $value): void { self::assert($value, '/^[A-Za-z0-9][A-Za-z0-9._-]{7,95}$/D', 'PROVIDER_B_INVALID_REQUEST_ID'); }
    public static function serviceCode(string $value): void { self::assert($value, '/^[a-z0-9][a-z0-9._-]{1,127}$/D', 'PROVIDER_B_CONTRACT_INVALID', 503); }
    public static function audience(string $value): void { self::assert($value, '/^[A-Za-z0-9][A-Za-z0-9._:-]{1,127}$/D', 'PROVIDER_B_CONTRACT_INVALID', 503); }
    public static function action(string $value): void { self::assert($value, '/^[a-z0-9][a-z0-9._:-]{1,127}$/D', 'PROVIDER_B_CONTRACT_INVALID', 503); }
    private static function assert(string $value, string $pattern, string $error, int $status = 400): void
    {
        if (preg_match($pattern, $value) !== 1) throw new ProviderException($error, $status);
    }
}
