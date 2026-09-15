<?php

declare(strict_types=1);

require __DIR__ . '/MachineServiceHttpClient.php';

function checkHttpClient(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$validate = new ReflectionMethod(MachineServiceHttpClient::class, 'assertUrl');
foreach (['https://iam.example.test/runtime/context/issue', 'http://localhost:8080/issue',
    'http://127.0.0.1/issue', 'http://[::1]:8080/issue'] as $url) {
    $validate->invoke(null, $url);
}
foreach (['http://localhost.example.test/issue', 'http://127.0.0.1.example.test/issue',
    'http://localhost@external.example.test/issue', 'https://user:password@iam.example.test/issue',
    'https:///issue', 'https://iam.example.test/issue?token=secret',
    'https://iam.example.test/issue#fragment', "https://iam.example.test/\nissue",
    'http://localhost\\@external.example.test/issue'] as $url) {
    $rejected = false;
    try { $validate->invoke(null, $url); } catch (InvalidArgumentException) { $rejected = true; }
    checkHttpClient($rejected, 'unsafe machine URL accepted: ' . $url);
}

// Intercept the real postJson stream invocation in this process only.
// This verifies transport options without opening sockets or following redirects.
final class MachineHttpProbe
{
    public $context;
    public static array $options = [];
    public static string $url = '';

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$url = $path;
        self::$options = stream_context_get_options($this->context);
        return false;
    }
}

checkHttpClient(stream_wrapper_unregister('https'), 'cannot isolate HTTPS test transport');
try {
    checkHttpClient(stream_wrapper_register('https', MachineHttpProbe::class), 'cannot register test transport');
    try {
        @MachineServiceHttpClient::postJson('https://iam.example.test/issue', ['service_code' => 'document-service'],
            ['Authorization' => 'Bearer fixture-secret']);
        throw new LogicException('probe unexpectedly completed a request');
    } catch (RuntimeException $error) {
        checkHttpClient($error->getMessage() === 'SandIAM 网络请求失败', 'unexpected probe error');
    }
} finally {
    stream_wrapper_restore('https');
}
$http = MachineHttpProbe::$options['http'] ?? [];
checkHttpClient(($http['follow_location'] ?? null) === 0 && ($http['max_redirects'] ?? null) === 0, 'credential transport can follow redirects');
checkHttpClient(($http['method'] ?? null) === 'POST' && ($http['timeout'] ?? null) === 10, 'request method or timeout changed');
checkHttpClient(str_contains($http['header'] ?? '', 'Authorization: Bearer fixture-secret'), 'credential header lost');
checkHttpClient(!str_contains($http['content'] ?? '', 'fixture-secret') && !str_contains(MachineHttpProbe::$url, 'fixture-secret'), 'credential leaked into body or URL');
foreach ([301, 302, 303, 307, 308] as $status) {
    $rejected = false;
    try { MachineServiceHttpClient::decode('{"data":{"context":"not-accepted"}}', $status); }
    catch (RuntimeException) { $rejected = true; }
    checkHttpClient($rejected, 'redirect response accepted as success');
}
echo "machine HTTP transport offline tests: PASS\n";
