<?php

declare(strict_types=1);

use Example\Standalone\Config;
use Example\Standalone\Controller;
use Example\Standalone\HttpProblem;
use Example\Standalone\PgsqlAuditWriter;
use Example\Standalone\PgsqlRepository;
use Example\Standalone\WorkItem;
use Sand\Iam\Sdk\AuthorizationDenied;
use Sand\Iam\Sdk\SandIamClient;
use Sand\Iam\Sdk\SandIamException;

require __DIR__ . '/vendor/autoload.php';

$config = Config::fromEnvironment();
$database = new PDO($config->databaseDsn, $config->databaseUser, $config->databasePassword, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$auditDatabase = new PDO($config->databaseDsn, $config->databaseUser, $config->databasePassword, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$iam = new SandIamClient($config->iamBaseUrl, $config->organizationCode, $config->applicationCode);

return new Controller(
    new PgsqlRepository($database),
    new PgsqlAuditWriter($auditDatabase),
    static function (string $accessToken, WorkItem $item, string $apiCode, string $requestId) use ($iam): string {
        try {
            $decision = $iam->authorizeEntity(
                $accessToken,
                $apiCode,
                $item,
                static fn (WorkItem $loaded): array => [
                    'organization_id' => $loaded->organizationId,
                    'owner_identity_id' => $loaded->ownerIdentityId,
                ],
                [],
                'v1',
                $requestId,
            );
        } catch (AuthorizationDenied) {
            throw new HttpProblem(403, 'access_denied');
        } catch (SandIamException $exception) {
            if ($exception->httpStatus === 401 || $exception->errorCode === 'SAND_IAM_AUTHENTICATION_FAILED') {
                throw new HttpProblem(401, 'SAND_IAM_AUTHENTICATION_FAILED');
            }
            if ($exception->httpStatus === 403 || in_array($exception->errorCode, [
                'SAND_IAM_RESOURCE_SCOPE_DENIED',
                'SAND_IAM_SERVICE_ACTION_FORBIDDEN',
                'SAND_IAM_CREDENTIAL_REVOKED',
            ], true)) {
                throw new HttpProblem(403, $exception->errorCode);
            }
            throw new HttpProblem(503, 'authorization_unavailable');
        } catch (\Throwable) {
            throw new HttpProblem(503, 'authorization_unavailable');
        }

        $identityId = $decision['identity_id'] ?? null;
        if (!is_int($identityId) && !ctype_digit((string) $identityId)) {
            throw new HttpProblem(503, 'authorization_protocol_invalid');
        }
        return (string) $identityId;
    },
    $config->readApiCode,
    $config->closeApiCode,
);
