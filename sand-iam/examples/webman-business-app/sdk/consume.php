<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/sdk/php/src/SandIamException.php';
require dirname(__DIR__, 3) . '/sdk/php/src/AuthorizationDenied.php';
require dirname(__DIR__, 3) . '/sdk/php/src/SandIamClient.php';

use Sand\Iam\Sdk\SandIamClient;

$config = require dirname(__DIR__) . '/generated/sand_iam.php';
$token = trim((string) getenv('SAND_IAM_ACCESS_TOKEN'));
if ($token === '') throw new RuntimeException('SAND_IAM_ACCESS_TOKEN 必须由运行环境或密钥管理系统注入');
$client = new SandIamClient(
    (string) getenv('SAND_IAM_BASE_URL'),
    $config['organization_code'],
    $config['application_code'],
);
$loadedMatter = (object) ['organization_id' => 1001, 'owner_identity_id' => 2001]; // Replace with a DB-loaded model.
$decision = $client->authorizeEntity($token, $config['actions']['MATTER_READ'], $loadedMatter, static fn (object $matter): array => [
    'organization_id' => $matter->organization_id,
    'owner_identity_id' => $matter->owner_identity_id,
], [], 'v1', 'matter-sdk-read-001');
var_export($decision);
