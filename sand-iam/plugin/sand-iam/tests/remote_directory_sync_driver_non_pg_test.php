<?php

declare(strict_types=1);

if (!class_exists('plugin\\sandadmin\\exception\\ApiException')) {
    eval('namespace plugin\\sandadmin\\exception; class ApiException extends \\RuntimeException { public function __construct(string $message = "", int $code = 0) { parent::__construct($message, $code); } }');
}

require_once dirname(__DIR__) . '/app/sync/SyncDriverInterface.php';
require_once dirname(__DIR__) . '/app/sync/RemoteDirectoryTransport.php';
require_once dirname(__DIR__) . '/app/sync/RemoteDirectoryTransportRegistry.php';
require_once dirname(__DIR__) . '/app/sync/AbstractRemoteDirectorySyncDriver.php';
require_once dirname(__DIR__) . '/app/sync/MicrosoftGraphDirectorySyncDriver.php';
require_once dirname(__DIR__) . '/app/sync/GoogleWorkspaceDirectorySyncDriver.php';
require_once dirname(__DIR__) . '/app/sync/KeycloakDirectorySyncDriver.php';

use plugin\SandIam\app\sync\GoogleWorkspaceDirectorySyncDriver;
use plugin\SandIam\app\sync\KeycloakDirectorySyncDriver;
use plugin\SandIam\app\sync\MicrosoftGraphDirectorySyncDriver;
use plugin\SandIam\app\sync\RemoteDirectoryTransport;
use plugin\SandIam\app\sync\RemoteDirectoryTransportRegistry;
use plugin\sandadmin\exception\ApiException;

final class RemoteDirectoryFakeTransport implements RemoteDirectoryTransport
{
    /** @var list<array{status:int,headers:array<string,string>,body:string}> */
    public array $responses = [];
    /** @var list<array{url:string,headers:array<string,string>}> */
    public array $requests = [];

    /** @param array<string,string> $headers @return array{status:int,headers:array<string,string>,body:string} */
    public function get(string $url, array $headers): array
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers];
        $response = array_shift($this->responses);
        if ($response === null) throw new RuntimeException('unexpected remote request');
        return $response;
    }

    /** @param array<string,mixed> $body */
    public function json(array $body, int $status = 200, array $headers = []): void
    {
        $this->responses[] = ['status' => $status, 'headers' => $headers, 'body' => json_encode($body, JSON_THROW_ON_ERROR)];
    }
}

function remoteDirectoryAssert(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, $message . PHP_EOL); exit(1); }
}

function remoteDirectoryExpect(callable $callback, string $code): void
{
    try { $callback(); } catch (ApiException $exception) { if (str_contains($exception->getMessage(), $code)) return; }
    fwrite(STDERR, "expected {$code}\n"); exit(1);
}

$transport = new RemoteDirectoryFakeTransport();
RemoteDirectoryTransportRegistry::replaceForTesting($transport);
try {
    $graph = ['tenant_id' => 'contoso.onmicrosoft.com', 'access_token' => 'graph-access-token'];
    $transport->responses[] = ['status' => 429, 'headers' => ['retry-after' => '0'], 'body' => '{}'];
    $transport->json([
        'value' => [
            ['id' => 'graph-user-1', 'displayName' => 'Alice', 'mail' => 'alice@example.test', 'accountEnabled' => true],
            ['id' => 'graph-user-2', '@removed' => ['reason' => 'changed']],
        ],
        '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/users/delta?$skiptoken=page-2',
    ]);
    $graphPage = MicrosoftGraphDirectorySyncDriver::pullPage($graph, null, 2);
    remoteDirectoryAssert(($graphPage['has_more'] ?? false) === true && ($graphPage['records'][1]['deleted'] ?? false) === true, 'Graph delta deletion/page mapping failed');
    remoteDirectoryAssert(count($transport->requests) === 2 && ($transport->requests[1]['headers']['Authorization'] ?? '') === 'Bearer graph-access-token', 'Graph retry or Bearer transport boundary failed');
    remoteDirectoryAssert(!str_contains($transport->requests[1]['url'], 'graph-access-token'), 'Graph token leaked into URL');
    $transport->json(['value' => [['id' => 'graph-user-3', 'displayName' => 'Carol', 'userPrincipalName' => 'carol@example.test']], '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/users/delta?$deltatoken=next-run']);
    $graphDone = MicrosoftGraphDirectorySyncDriver::pullPage($graph, $graphPage['next_cursor'], 2);
    remoteDirectoryAssert(($graphDone['has_more'] ?? true) === false && str_contains((string) $graphDone['next_cursor'], '$deltatoken='), 'Graph delta cursor was not retained');
    $transport->json(['value' => [], '@odata.nextLink' => 'https://evil.example.test/v1.0/users/delta?$skiptoken=x']);
    remoteDirectoryExpect(static fn () => MicrosoftGraphDirectorySyncDriver::pullPage($graph, null, 2), 'SAND_IAM_SYNC_CURSOR_INVALID');
    $transport->json(['value' => [['id' => 'duplicate', 'displayName' => 'One'], ['id' => 'duplicate', 'displayName' => 'Two']], '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/users/delta?$deltatoken=x']);
    remoteDirectoryExpect(static fn () => MicrosoftGraphDirectorySyncDriver::pullPage($graph, null, 2), 'SAND_IAM_SYNC_DUPLICATE_SUBJECT');

    $google = ['domain' => 'example.test', 'access_token' => 'google-access-token'];
    $transport->json(['users' => [['id' => 'google-user-1', 'primaryEmail' => 'admin@example.test', 'name' => ['fullName' => 'Google Admin'], 'suspended' => false]], 'nextPageToken' => 'page-token-2']);
    $googlePage = GoogleWorkspaceDirectorySyncDriver::pullPage($google, null, 1);
    remoteDirectoryAssert(($googlePage['next_cursor'] ?? '') === 'page-token-2' && ($googlePage['records'][0]['attributes']['external_subject'] ?? '') === 'google-user-1', 'Google users.list mapping failed');
    remoteDirectoryAssert(str_contains($transport->requests[array_key_last($transport->requests)]['url'], 'pageToken') === false, 'Google first page unexpectedly has a cursor');
    remoteDirectoryExpect(static fn () => GoogleWorkspaceDirectorySyncDriver::pullPage($google, "bad cursor\n", 1), 'SAND_IAM_SYNC_CURSOR_INVALID');

    $keycloak = ['base_url' => 'https://keycloak.example.test', 'realm' => 'employees', 'access_token' => 'keycloak-access-token'];
    $transport->json([['id' => 'kc-user-1', 'username' => 'alice', 'email' => 'alice@example.test', 'enabled' => false], ['id' => 'kc-user-2', 'firstName' => 'Bob', 'lastName' => 'Keycloak', 'enabled' => true]]);
    $keycloakPage = KeycloakDirectorySyncDriver::pullPage($keycloak, null, 2);
    remoteDirectoryAssert(($keycloakPage['has_more'] ?? false) === true && ($keycloakPage['records'][0]['attributes']['status'] ?? '') === 'disabled', 'Keycloak pagination/status mapping failed');
    remoteDirectoryAssert(str_contains($transport->requests[array_key_last($transport->requests)]['url'], '/admin/realms/employees/users?first=0&max=2'), 'Keycloak users endpoint or first/max pagination drifted');
    $transport->json([]);
    $keycloakDone = KeycloakDirectorySyncDriver::pullPage($keycloak, $keycloakPage['next_cursor'], 2);
    remoteDirectoryAssert(($keycloakDone['has_more'] ?? true) === false && array_key_exists('next_cursor', $keycloakDone) && $keycloakDone['next_cursor'] === null, 'Keycloak final page cursor handling failed');
} finally {
    RemoteDirectoryTransportRegistry::replaceForTesting(null);
}

echo "remote directory sync driver non-PG tests passed\n";
