<?php
declare(strict_types=1);

require __DIR__ . '/authority_hotfix_behavior_non_pg_test.php';
require dirname(__DIR__) . '/app/admin/controller/AuthPolicyController.php';

use plugin\SandIam\app\admin\controller\AuthPolicyController;
use plugin\sandadmin\exception\ApiException;

function clearCheck(callable $operation, bool $allowed, string $label): void
{
    try {
        $operation();
        if (!$allowed) throw new RuntimeException($label . ': unexpectedly accepted');
    } catch (ApiException $error) {
        if ($allowed || $error->getCode() !== 400) throw new RuntimeException($label . ': unexpected rejection', 0, $error);
    }
}

$auth = new AuthPolicyController();
$validateAuth = new ReflectionMethod($auth, 'assertReferences');
$existingAuth = (object) [
    'application_id' => 1,
    'webauthn_rp_id' => 'example.com',
    'webauthn_allowed_origins' => ['https://example.com'],
];
clearCheck(fn () => $validateAuth->invoke($auth, [
    'webauthn_rp_id' => '', 'webauthn_allowed_origins' => [],
], $existingAuth), true, 'explicit pair clears WebAuthn');
clearCheck(fn () => $validateAuth->invoke($auth, [], $existingAuth), true, 'omission retains valid pair');
clearCheck(fn () => $validateAuth->invoke($auth, ['webauthn_allowed_origins' => []], $existingAuth), false, 'omitted RP retains old value');
clearCheck(fn () => $validateAuth->invoke($auth, ['webauthn_rp_id' => ''], $existingAuth), false, 'omitted origins retain old value');
clearCheck(fn () => $validateAuth->invoke($auth, ['webauthn_rp_id' => null, 'webauthn_allowed_origins' => []], $existingAuth), false, 'null RP does not clear');
clearCheck(fn () => $validateAuth->invoke($auth, ['application_id' => 999, 'webauthn_rp_id' => '', 'webauthn_allowed_origins' => []], $existingAuth), false, 'missing application still rejected');

// The included fixture supplies the real API validator and enabled app/resource/action.
$existingApi = (object) $validPayload;
clearCheck(fn () => $assertReferences->invoke($controller, ['required_scope' => ''], $existingApi), true, 'explicit scope clear');
clearCheck(fn () => $assertReferences->invoke($controller, [], $existingApi), true, 'valid scope omission');
$invalidExistingApi = (object) [...$validPayload, 'required_scope' => 'invalid scope'];
clearCheck(fn () => $assertReferences->invoke($controller, [], $invalidExistingApi), false, 'omission revalidates existing scope');
clearCheck(fn () => $assertReferences->invoke($controller, ['required_scope' => ''], $invalidExistingApi), true, 'explicit empty overrides old scope');
clearCheck(fn () => $assertReferences->invoke($controller, ['resource_id' => 999, 'required_scope' => ''], $existingApi), false, 'missing resource still rejected');

echo "Optional text clear contracts PASS (real protected validators; no persistence or permission middleware)\n";
