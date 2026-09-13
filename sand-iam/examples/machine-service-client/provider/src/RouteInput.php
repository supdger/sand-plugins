<?php
declare(strict_types=1);
namespace Sand\Iam\Example\ProviderB;
final class RouteInput
{
    public static function validate(string|false $raw, string $context, string $idempotencyKey): void
    {
        if ($raw === false || strlen($raw) > 4096) throw new ProviderException('PROVIDER_B_INVALID_REQUEST', 400);
        try {$body = $raw === '' ? [] : json_decode($raw, true, 16, JSON_THROW_ON_ERROR);} catch (\JsonException) {throw new ProviderException('PROVIDER_B_INVALID_REQUEST', 400);}
        if (!is_array($body) || $body !== []) throw new ProviderException('PROVIDER_B_INVALID_REQUEST', 400);
        if ($context === '' || $idempotencyKey === '') throw new ProviderException('PROVIDER_B_AUTHORIZATION_HEADERS_REQUIRED', 401);
    }
}
