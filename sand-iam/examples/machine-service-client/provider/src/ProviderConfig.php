<?php
declare(strict_types=1);
namespace Sand\Iam\Example\ProviderB;
final class ProviderConfig
{
    private function __construct(
        public readonly string $iamBaseUrl, public readonly string $organizationCode, public readonly string $applicationCode,
        public readonly string $databaseDsn, public readonly string $databaseUser, public readonly string $databasePassword,
        public readonly string $serviceCode, public readonly string $audience, public readonly string $action,
    ) {}
    public static function fromEnvironment(): self
    {
        return self::fromArray(array_combine(
            ['SAND_IAM_BASE_URL','SAND_IAM_ORGANIZATION_CODE','SAND_IAM_APPLICATION_CODE','PROVIDER_B_DATABASE_DSN','PROVIDER_B_DATABASE_USER','PROVIDER_B_DATABASE_PASSWORD','PROVIDER_B_SERVICE_CODE','PROVIDER_B_AUDIENCE','PROVIDER_B_ACTION'],
            array_map('getenv', ['SAND_IAM_BASE_URL','SAND_IAM_ORGANIZATION_CODE','SAND_IAM_APPLICATION_CODE','PROVIDER_B_DATABASE_DSN','PROVIDER_B_DATABASE_USER','PROVIDER_B_DATABASE_PASSWORD','PROVIDER_B_SERVICE_CODE','PROVIDER_B_AUDIENCE','PROVIDER_B_ACTION'])
        ));
    }
    /** @param array<string,string|false> $env */
    public static function fromArray(array $env): self
    {
        $get = static fn(string $key): string => trim(is_string($env[$key] ?? false) ? $env[$key] : '');
        [$url, $org, $app, $dsn] = [rtrim($get('SAND_IAM_BASE_URL'), '/'), $get('SAND_IAM_ORGANIZATION_CODE'), $get('SAND_IAM_APPLICATION_CODE'), $get('PROVIDER_B_DATABASE_DSN')];
        if ($url === '' || $org === '' || $app === '' || $dsn === '') throw new ProviderException('PROVIDER_B_CONFIGURATION_INCOMPLETE', 503);
        if (!str_starts_with($dsn, 'pgsql:')) throw new ProviderException('PROVIDER_B_POSTGRESQL_REQUIRED', 503);
        $service = $get('PROVIDER_B_SERVICE_CODE') ?: ProviderProtocol::SERVICE_CODE;
        $audience = $get('PROVIDER_B_AUDIENCE') ?: ProviderProtocol::AUDIENCE;
        $action = $get('PROVIDER_B_ACTION') ?: ProviderProtocol::ACTION;
        ProviderProtocol::serviceCode($service);
        ProviderProtocol::audience($audience);
        ProviderProtocol::action($action);
        return new self($url, $org, $app, $dsn, $get('PROVIDER_B_DATABASE_USER'), $get('PROVIDER_B_DATABASE_PASSWORD'), $service, $audience, $action);
    }
}
