<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

$models = dirname(__DIR__) . '/app/model';

/** @var array<string, list<string>> $expected */
$expected = [
    'Identity.php' => ['external_guest_ref_hash'],
    'IdentityAuth.php' => ['password_hash'],
    'AuthChallenge.php' => ['token_hash', 'encrypted_challenge', 'encrypted_user_handle'],
    'AuthRateLimit.php' => ['subject_hash'],
    'AuthRefreshToken.php' => ['token_hash'],
    'AuthSession.php' => ['access_token_hash', 'refresh_token_hash', 'previous_refresh_token_hash', 'ip_hash'],
    'AuthVerification.php' => ['destination_hash', 'code_hash'],
    'AuthorizationCode.php' => ['code_hash', 'encrypted_nonce', 'nonce_hash'],
    'CasLoginRequest.php' => ['request_hash'],
    'CasTicket.php' => ['ticket_hash'],
    'Credential.php' => ['secret_hash', 'delete_time'],
    'OAuthAuthorizationRequest.php' => ['request_hash', 'csrf_hash', 'encrypted_payload'],
    'OAuthClient.php' => ['secret_hash'],
    'FederationHandoff.php' => ['code_hash'],
    'FederationTransaction.php' => ['state_hash', 'browser_binding_hash', 'nonce_hash', 'assertion_hash', 'encrypted_pkce_verifier', 'handoff_state'],
    'IdentityImportRow.php' => ['encrypted_payload'],
    'IdentityInvitation.php' => ['target_hash', 'encrypted_target', 'token_hash', 'encrypted_delivery_token'],
    'IdentityProvider.php' => ['encrypted_config'],
    'MessageProvider.php' => ['encrypted_config'],
    'MfaFactor.php' => ['encrypted_secret'],
    'MfaRecoveryCode.php' => ['code_hash'],
    'OAuthRegistrationToken.php' => ['token_hash', 'last_used_ip_hash'],
    'OAuthToken.php' => ['token_hash'],
    'OidcLogoutDelivery.php' => ['encrypted_logout_token'],
    'OidcSigningKey.php' => ['encrypted_private_key', 'encryption_version'],
    'RadiusAccountingEvent.php' => ['session_reference', 'user_reference', 'request_fingerprint'],
    'RadiusAccountingSession.php' => ['session_reference', 'user_reference'],
    'RadiusNas.php' => ['encrypted_shared_secret'],
    'RadiusReplay.php' => ['request_fingerprint'],
    'ScimToken.php' => ['token_hash'],
    'SecurityAlert.php' => ['fingerprint'],
    'SyncConnector.php' => ['encrypted_config', 'encrypted_cursor'],
    'SyncOutbox.php' => ['encrypted_payload'],
    'SyncResource.php' => ['source_key_hash', 'encrypted_snapshot'],
    'WebhookEndpoint.php' => ['encrypted_secret'],
];

$declaredHiddenModels = [];
foreach (glob($models . '/*.php') ?: [] as $path) {
    $source = file_get_contents($path);
    if (is_string($source) && preg_match('/protected \$hidden\s*=\s*\[([^]]*)\]/s', $source) === 1) {
        $declaredHiddenModels[] = basename($path);
    }
}
sort($declaredHiddenModels);
$expectedModels = array_keys($expected);
sort($expectedModels);
if ($declaredHiddenModels !== $expectedModels) {
    fwrite(STDERR, 'sensitive model registry drifted: declared=' . implode(',', $declaredHiddenModels) . '; expected=' . implode(',', $expectedModels) . PHP_EOL);
    exit(1);
}

foreach ($expected as $file => $fields) {
    $source = file_get_contents($models . '/' . $file);
    if (!is_string($source)) {
        fwrite(STDERR, "cannot read model {$file}\n");
        exit(1);
    }
    if (!preg_match('/protected \\$hidden\s*=\s*\[([^]]*)\]/s', $source, $match)) {
        fwrite(STDERR, "model {$file} does not declare hidden fields\n");
        exit(1);
    }
    preg_match_all('/[\'\"]([^\'\"]+)[\'\"]/', $match[1], $fieldMatches);
    $declaredFields = $fieldMatches[1] ?? [];
    sort($declaredFields);
    $expectedFields = $fields;
    sort($expectedFields);
    if ($declaredFields !== $expectedFields) {
        fwrite(STDERR, "model {$file} hidden-field registry drifted\n");
        exit(1);
    }
}

// The package deliberately does not ship the SandAdmin ORM. This minimal base
// implements the same hidden-field serialization contract so the model is
// exercised through toArray() and JSON serialization without a database.
if (!class_exists('plugin\\sandadmin\\basic\\think\\BaseModel')) {
    eval(<<<'PHP'
namespace plugin\sandadmin\basic\think;
class BaseModel implements \JsonSerializable {
    protected $hidden = [];
    protected $attributes = [];
    public function __construct(array $attributes = []) { $this->attributes = $attributes; }
    public function toArray(): array { return array_diff_key($this->attributes, array_fill_keys($this->hidden, true)); }
    public function jsonSerialize(): mixed { return $this->toArray(); }
}
PHP);
}

require_once $models . '/AbstractSandIamModel.php';
foreach ($expected as $file => $fields) {
    require_once $models . '/' . $file;
    $class = 'plugin\\SandIam\\app\\model\\' . substr($file, 0, -4);
    $attributes = ['id' => 1, 'public_label' => 'visible'];
    foreach ($fields as $field) $attributes[$field] = 'must-not-serialize:' . $file . ':' . $field;
    $model = new $class($attributes);
    $array = $model->toArray();
    $json = json_encode($model, JSON_THROW_ON_ERROR);
    foreach ($fields as $field) {
        if (array_key_exists($field, $array) || str_contains($json, 'must-not-serialize:' . $file . ':' . $field)) {
            fwrite(STDERR, "model {$file} serialization exposes {$field}\n");
            exit(1);
        }
    }
}

echo 'sensitive model serialization non-PG contract checks passed' . PHP_EOL;
