<?php

declare(strict_types=1);

namespace Example\Standalone;

use RuntimeException;

final readonly class Config
{
    public function __construct(
        public string $databaseDsn,
        public string $databaseUser,
        public string $databasePassword,
        public string $iamBaseUrl,
        public string $organizationCode,
        public string $applicationCode,
        public string $readApiCode,
        public string $closeApiCode,
    ) {}

    public static function fromEnvironment(): self
    {
        $dsn = trim((string) getenv('BUSINESS_DATABASE_DSN'));
        if (!str_starts_with($dsn, 'pgsql:')) {
            throw new RuntimeException('BUSINESS_DATABASE_DSN must be a configured PostgreSQL DSN');
        }

        $baseUrl = rtrim(trim((string) getenv('SAND_IAM_BASE_URL')), '/');
        $organizationCode = trim((string) getenv('SAND_IAM_ORGANIZATION_CODE'));
        $applicationCode = trim((string) getenv('SAND_IAM_APPLICATION_CODE'));
        $readApiCode = trim((string) getenv('SAND_IAM_READ_API_CODE'));
        $closeApiCode = trim((string) getenv('SAND_IAM_CLOSE_API_CODE'));
        if ($baseUrl === ''
            || !self::isStableCode($organizationCode)
            || !self::isStableCode($applicationCode)
            || !self::isApiCode($readApiCode)
            || !self::isApiCode($closeApiCode)
            || $readApiCode === $closeApiCode) {
            throw new RuntimeException('SandIAM consumer configuration is incomplete');
        }

        return new self(
            $dsn,
            (string) getenv('BUSINESS_DATABASE_USER'),
            (string) getenv('BUSINESS_DATABASE_PASSWORD'),
            $baseUrl,
            $organizationCode,
            $applicationCode,
            $readApiCode,
            $closeApiCode,
        );
    }

    private static function isStableCode(string $value): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $value) === 1;
    }

    private static function isApiCode(string $value): bool
    {
        return preg_match('/^[a-z][a-z0-9_.:-]{1,95}$/', $value) === 1;
    }
}
