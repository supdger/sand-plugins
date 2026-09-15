<?php

declare(strict_types=1);

namespace plugin\sandadmin\exception {
    class ApiException extends \RuntimeException {}
}

namespace {
    require __DIR__ . '/../app/integration/Captcha/TurnstileClient.php';
    use plugin\SandIam\app\integration\Captcha\TurnstileClient;
    use plugin\sandadmin\exception\ApiException;

    $checks = 0;
    function check(bool $condition, string $message): void
    {
        global $checks;
        if (!$condition) throw new \RuntimeException($message);
        ++$checks;
    }
    $config = ['site_key' => 'offline-public-key', 'secret_key' => 'offline-provider-secret', 'hostnames' => ['portal.example.test']];
    $context = ['application_id' => 12, 'action' => 'login'];
    $challenge = TurnstileClient::publicChallenge($context, $config);
    check(array_keys($challenge) === ['kind', 'site_key', 'action', 'application_binding'], 'public projection only');
    check(!str_contains(json_encode($challenge), $config['secret_key']), 'no secret projection');
    $accepted = ['success' => true, 'hostname' => 'portal.example.test', 'action' => 'login', 'cdata' => $challenge['application_binding']];
    $calls = [];
    $response = ['status' => 200, 'body' => json_encode($accepted)];
    $client = new TurnstileClient(static function (array $body) use (&$calls, &$response): array {
        $calls[] = $body;
        return $response;
    });
    check($client->validate('offline-token', $context, $config), 'valid provider response');
    check($calls === [['secret' => $config['secret_key'], 'response' => 'offline-token']], 'only required provider request fields');
    foreach ([
        ['success' => false], ['hostname' => 'evil.example.test'], ['action' => 'register'],
        ['cdata' => 'wrong-application'], ['cdata' => null], ['success' => 1],
    ] as $override) {
        $response['body'] = json_encode(array_replace($accepted, $override));
        try {
            check(!$client->validate('offline-token', $context, $config), 'reject mismatched provider assertion');
        } catch (ApiException $error) {
            check($error->getCode() === 503, 'malformed provider assertion unavailable');
        }
    }
    $response['body'] = json_encode($accepted);
    check(!$client->validate('offline-token', ['application_id' => 13, 'action' => 'login'], $config), 'cross application token denied');
    check(!$client->validate('offline-token', $context, array_replace($config, ['secret_key' => 'rotated-secret'])), 'rotation invalidates binding');
    $before = count($calls);
    check(!$client->validate('', $context, $config), 'empty token denied');
    check(!$client->validate(str_repeat('x', 2049), $context, $config), 'oversized token denied');
    check(count($calls) === $before, 'invalid tokens do not invoke provider');
    foreach ([
        ['status' => 503, 'body' => json_encode($accepted)],
        ['status' => 200, 'body' => 'not-json'],
        ['status' => 200, 'body' => str_repeat('x', 65537)],
    ] as $response) {
        try {
            $client->validate('offline-token', $context, $config);
            throw new \RuntimeException('provider failure accepted');
        } catch (ApiException $error) {
            check($error->getCode() === 503 && $error->getMessage() === 'SAND_IAM_CAPTCHA_UNAVAILABLE', 'provider failure sanitized');
        }
    }
    $unavailable = new TurnstileClient(static function (): never { throw new \RuntimeException('sensitive transport details'); });
    try {
        $unavailable->validate('offline-token', $context, $config);
        throw new \RuntimeException('transport failure accepted');
    } catch (ApiException $error) {
        check($error->getMessage() === 'SAND_IAM_CAPTCHA_UNAVAILABLE', 'transport error does not leak');
    }
    echo "Turnstile offline checks: $checks passed; no database or network used\n";
}
