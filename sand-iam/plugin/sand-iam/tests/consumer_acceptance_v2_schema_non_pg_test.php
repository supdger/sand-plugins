<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

$passed = 0;
$assert = static function (bool $ok, string $message) use (&$passed): void {
    if (!$ok) throw new RuntimeException($message);
    ++$passed;
};
$root = dirname(__DIR__, 3);
require_once $root . '/tools/consumer-acceptance-v2/LiveContract.php';
$readJson = static function (string $path): array {
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) throw new RuntimeException('JSON object expected: ' . $path);
    return $decoded;
};

/** @param array<string,mixed> $schema @param array<string,mixed> $rootSchema */
$validate = static function (array $schema, mixed $value, array $rootSchema) use (&$validate): void {
    if (isset($schema['$ref'])) {
        $ref = $schema['$ref'];
        if (!is_string($ref) || preg_match('~^#/\$defs/([A-Za-z][A-Za-z0-9_]*)$~', $ref, $match) !== 1 || !isset($rootSchema['$defs'][$match[1]]) || !is_array($rootSchema['$defs'][$match[1]])) {
            throw new RuntimeException('unresolvable schema reference');
        }
        $validate($rootSchema['$defs'][$match[1]], $value, $rootSchema);
        return;
    }
    if (array_key_exists('const', $schema) && $value !== $schema['const']) throw new RuntimeException('const mismatch');
    if (isset($schema['enum']) && (!is_array($schema['enum']) || !in_array($value, $schema['enum'], true))) throw new RuntimeException('enum mismatch');
    if (($schema['type'] ?? null) === 'object') {
        if (!is_array($value) || array_is_list($value)) throw new RuntimeException('object expected');
        $properties = $schema['properties'] ?? null;
        $required = $schema['required'] ?? null;
        if (!is_array($properties) || !is_array($required) || ($schema['additionalProperties'] ?? null) !== false) throw new RuntimeException('closed object schema expected');
        foreach ($required as $key) if (!is_string($key) || !array_key_exists($key, $value)) throw new RuntimeException('required property missing');
        foreach ($value as $key => $item) {
            if (!isset($properties[$key]) || !is_array($properties[$key])) throw new RuntimeException('unknown property');
            $validate($properties[$key], $item, $rootSchema);
        }
        return;
    }
    if (($schema['type'] ?? null) === 'array') {
        if (!is_array($value) || !array_is_list($value)) throw new RuntimeException('array expected');
        if (isset($schema['minItems']) && count($value) < $schema['minItems']) throw new RuntimeException('too few array items');
        if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) throw new RuntimeException('too many array items');
        foreach (($schema['prefixItems'] ?? []) as $index => $itemSchema) {
            if (!is_array($itemSchema) || !array_key_exists($index, $value)) throw new RuntimeException('prefix item missing');
            $validate($itemSchema, $value[$index], $rootSchema);
        }
        if (($schema['items'] ?? null) === false && count($value) > count($schema['prefixItems'] ?? [])) throw new RuntimeException('additional array item');
        return;
    }
    if (($schema['type'] ?? null) === 'string') {
        if (!is_string($value)) throw new RuntimeException('string expected');
        if (isset($schema['pattern']) && (!is_string($schema['pattern']) || preg_match('#' . $schema['pattern'] . '#D', $value) !== 1)) throw new RuntimeException('string pattern mismatch');
        if (($schema['format'] ?? null) === 'date-time') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
            $errors = DateTimeImmutable::getLastErrors();
            if (!$date instanceof DateTimeImmutable || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0)) || $date->format('Y-m-d\TH:i:s\Z') !== $value) throw new RuntimeException('UTC date-time mismatch');
        }
        if (($schema['contentEncoding'] ?? null) === 'base64') {
            $decoded = base64_decode($value, true);
            if (!is_string($decoded) || strlen($decoded) !== SODIUM_CRYPTO_SIGN_BYTES) throw new RuntimeException('base64 signature mismatch');
        }
        return;
    }
    if (($schema['type'] ?? null) === 'boolean' && !is_bool($value)) throw new RuntimeException('boolean expected');
};

/** @param array<string,mixed> $schema @param array<string,mixed> $rootSchema */
$inspect = static function (array $schema, array $rootSchema) use (&$inspect, $assert): void {
    if (isset($schema['$ref'])) {
        $ref = $schema['$ref'];
        $assert(is_string($ref) && preg_match('~^#/\$defs/([A-Za-z][A-Za-z0-9_]*)$~', $ref, $match) === 1 && isset($rootSchema['$defs'][$match[1]]), 'schema reference exists');
        return;
    }
    if (($schema['type'] ?? null) === 'object') {
        $properties = $schema['properties'] ?? null;
        $required = $schema['required'] ?? null;
        $assert(($schema['additionalProperties'] ?? null) === false && is_array($properties) && is_array($required), 'nested object is closed and declared');
        foreach ($required as $key) $assert(is_string($key) && array_key_exists($key, $properties), 'required property has schema');
        foreach ($properties as $property) if (is_array($property)) $inspect($property, $rootSchema);
    }
    if (($schema['type'] ?? null) === 'array') {
        foreach (($schema['prefixItems'] ?? []) as $item) if (is_array($item)) $inspect($item, $rootSchema);
        if (is_array($schema['items'] ?? null)) $inspect($schema['items'], $rootSchema);
    }
};

$schemas = [
    'plan' => $readJson($root . '/tools/schemas/consumer-acceptance-v2-plan.schema.json'),
    'receipt' => $readJson($root . '/tools/schemas/consumer-acceptance-v2-receipt.schema.json'),
    'report' => $readJson($root . '/tools/schemas/consumer-acceptance-v2-report.schema.json'),
];
$fixtures = [
    'plan' => $readJson($root . '/tools/fixtures/consumer-acceptance-v2-plan.template.json'),
    'receipt' => $readJson($root . '/tools/fixtures/consumer-acceptance-v2-receipt.template.json'),
    'report' => $readJson($root . '/tools/fixtures/consumer-acceptance-v2-report.fixture.json'),
];
foreach ($schemas as $name => $schema) {
    $assert(is_array($schema['$defs'] ?? null) && $schema['$defs'] !== [], $name . ' exposes definitions');
    $inspect($schema, $schema);
    foreach ($schema['$defs'] as $definition) {
        $assert(is_array($definition), $name . ' definition is an object schema');
        $inspect($definition, $schema);
    }
    $validate($schema, $fixtures[$name], $schema);
    $assert(true, $name . ' valid fixture passes strict structural schema check');
}
$assert(!array_key_exists('capabilities', $schemas['plan']['properties']), 'plan has no capability self-declaration');
$fixtureSignature = base64_decode($fixtures['receipt']['signature'], true);
$assert($fixtures['receipt']['key_id'] === 'untrusted_fixture_key' && $fixtures['receipt']['authorizer'] === 'untrusted_fixture_authorizer' && is_string($fixtureSignature) && strlen($fixtureSignature) === SODIUM_CRYPTO_SIGN_BYTES, 'receipt fixture is explicitly untrusted and has a 64-byte placeholder signature');

$copy = static fn (array $value): array => json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
$reject = static function (callable $call) use ($assert): void {
    try { $call(); } catch (Throwable) { $assert(true, 'invalid fixture rejected'); return; }
    throw new RuntimeException('invalid fixture accepted');
};
$unknownNested = $copy($fixtures['plan']);
$unknownNested['candidate']['unexpected'] = 'forbidden';
$reject(static fn () => $validate($schemas['plan'], $unknownNested, $schemas['plan']));
$replacedPlanStep = $copy($fixtures['plan']);
$replacedPlanStep['actions'][0] = 'other_action';
$reject(static fn () => $validate($schemas['plan'], $replacedPlanStep, $schemas['plan']));
$replacedReportStep = $copy($fixtures['report']);
$replacedReportStep['steps'][0] = 'other_action';
$reject(static fn () => $validate($schemas['report'], $replacedReportStep, $schemas['report']));
$invalidDate = $copy($fixtures['receipt']);
$invalidDate['issued_at'] = '2026-99-99T09:00:00Z';
$reject(static fn () => $validate($schemas['receipt'], $invalidDate, $schemas['receipt']));
$offsetDate = $copy($fixtures['receipt']);
$offsetDate['expires_at'] = '2026-09-13T11:00:00+00:00';
$reject(static fn () => $validate($schemas['receipt'], $offsetDate, $schemas['receipt']));
$shortSignature = $copy($fixtures['receipt']);
$shortSignature['signature'] = 'YQ==';
$reject(static fn () => $validate($schemas['receipt'], $shortSignature, $schemas['receipt']));
$invalidSignature = $copy($fixtures['receipt']);
$invalidSignature['signature'] = str_repeat('!', 86) . '==';
$reject(static fn () => $validate($schemas['receipt'], $invalidSignature, $schemas['receipt']));

$temporary = sys_get_temp_dir() . '/sand-iam-v2-schema-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
try {
    $plan = $fixtures['plan'];
    $keys = sodium_crypto_sign_keypair();
    $trustedKey = $temporary . '/trusted.ed25519.pub';
    file_put_contents($trustedKey, base64_encode(sodium_crypto_sign_publickey($keys)) . "\n");
    $receipt = static function (string $type) use ($plan, $keys): array {
        $publicKey = sodium_crypto_sign_publickey($keys);
        $value = ['schema' => 'sand-iam.consumer-acceptance-v2-receipt/v1', 'type' => $type, 'algorithm' => 'Ed25519', 'key_id' => 'test_key', 'public_key_sha256' => hash('sha256', $publicKey), 'issued_at' => '2026-09-13T09:00:00Z', 'expires_at' => '2026-09-13T11:00:00Z', 'run_id' => $plan['run_id'], 'plan_sha256' => hash('sha256', ConsumerAcceptanceV2ReceiptVerifier::canonical($plan)), 'candidate' => $plan['candidate'], 'origins' => $plan['origins'], 'database' => $plan['database'], 'scope_cleanup' => $plan['scope_cleanup'], 'fixture_manifest_sha256' => $plan['fixture_manifest_sha256'], 'action_registry_sha256' => ConsumerAcceptanceV2ActionRegistry::manifestHash(), 'authorizer' => 'independent_authorizer'];
        $value['signature'] = base64_encode(sodium_crypto_sign_detached(ConsumerAcceptanceV2ReceiptVerifier::canonical($value), sodium_crypto_sign_secretkey($keys)));
        return $value;
    };
    $publicKey = sodium_crypto_sign_publickey($keys);
    $observed = ['schema' => 'sand-iam.consumer-acceptance-v2-capability/v1', 'algorithm' => 'Ed25519', 'key_id' => 'test_key', 'public_key_sha256' => hash('sha256', $publicKey), 'issued_at' => '2026-09-13T09:00:00Z', 'expires_at' => '2026-09-13T11:00:00Z', 'host_revision_sha256' => $plan['candidate']['host_sha256'], 'plan_sha256' => hash('sha256', ConsumerAcceptanceV2ReceiptVerifier::canonical($plan)), 'capability' => 'consumer_l04_cleanup_v1', 'version' => 'v1', 'enabled' => true, 'scope_sha256' => $plan['scope_cleanup']['scope_sha256']];
    $observed['signature'] = base64_encode(sodium_crypto_sign_detached(ConsumerAcceptanceV2ReceiptVerifier::canonical($observed), sodium_crypto_sign_secretkey($keys)));
    $authorized = ConsumerAcceptanceV2LiveContract::authorize($plan, [$receipt('authorization_gate'), $receipt('fixture_ownership_gate'), $receipt('cleanup_gate')], $trustedKey, new DateTimeImmutable('2026-09-13T10:00:00Z'), $observed, $trustedKey);
    $blocked = ConsumerAcceptanceV2LiveContract::authorize($plan, [], $trustedKey, new DateTimeImmutable('2026-09-13T10:00:00Z'));
    $validate($schemas['report'], $authorized, $schemas['report']);
    $validate($schemas['report'], $blocked, $schemas['report']);
    $assert($authorized['status'] === 'authorized_offline' && $blocked['status'] === 'preflight_blocked', 'production authorize reports both schema-valid offline states');
} finally {
    foreach (glob($temporary . '/*') ?: [] as $file) unlink($file);
    rmdir($temporary);
}

echo "consumer acceptance v2 schema structural checks (non-standard validator) passed={$passed}\n";
