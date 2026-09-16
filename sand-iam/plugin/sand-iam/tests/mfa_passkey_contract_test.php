<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

function contractFail(string $message): never { fwrite(STDERR, "IAM-T02 contract failed: {$message}\n"); exit(1); }
function contractRequire(string $file, string $needle): void { $source = file_get_contents($file); if (!is_string($source) || !str_contains($source, $needle)) contractFail("{$file} is missing {$needle}"); }

$root = dirname(__DIR__, 3);
$service = $root . '/plugin/sand-iam/app/service/MfaService.php';
$migration = $root . '/migrations/004_mfa_passkey.pgsql';
$challengeIdentityMigration = $root . '/migrations/040_passkey_auth_challenge_identity.pgsql';
$routes = $root . '/plugin/sand-iam/config/route.php';
$install = $root . '/install.sql';
$pluginInstall = $root . '/plugin/sand-iam/install.sql';
foreach (['assertCurrentPassword', 'assertMfaAttemptAllowed', 'RequestId::normalize', 'IdempotencyService', 'identity.totp_start', 'identity.totp_confirm', 'identity.recovery_regenerate', 'identity.passkey_register_finish', 'identity.passkey_auth_options', 'identity.passkey_auth_finish', 'identity.mfa_challenge_verify', 'challenge_token_proof', 'recoverChallengeResponse', 'recoverChallengeVerificationResult', 'recoverSessionTokenResponse', 'sealSessionTokenResponse', 'password_proof', 'code_proof', 'idempotencyActor', 'mfa_encryption_keys', 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt', 'last_used_counter', 'SAND_IAM_MFA_TOTP_REPLAYED', 'SAND_IAM_MFA_CHALLENGE_RETRY_UNAVAILABLE', 'webauthn.create', 'webauthn.get', 'openssl_verify', 'SAND_IAM_PASSKEY_BACKUP_STATE_INVALID', 'SAND_IAM_PASSKEY_SIGN_COUNT_REPLAYED', 'crossOrigin', 'userHandle'] as $needle) contractRequire($service, $needle);
$serviceSource = file_get_contents($service);
if (!is_string($serviceSource)) contractFail('cannot read MFA service');
$authenticationFinishStart = strpos($serviceSource, 'public function passkeyAuthenticationFinish');
$factorRecordStart = strpos($serviceSource, 'private function factorRecord', $authenticationFinishStart === false ? 0 : $authenticationFinishStart);
if ($authenticationFinishStart === false
    || $factorRecordStart === false
    || !str_contains(substr($serviceSource, $authenticationFinishStart, $factorRecordStart - $authenticationFinishStart), "'identity_id' => (int) \$identity->id")) {
    contractFail('passkey authentication finish does not bind the consumed challenge to the resolved identity');
}
foreach (['sand_iam_mfa_factor', 'sand_iam_mfa_recovery_code', 'sand_iam_webauthn_credential', 'sand_iam_auth_challenge', 'FOREIGN KEY (identity_id, application_id)', 'FOREIGN KEY (factor_id, application_id, identity_id)', 'uk_sand_iam_mfa_factor_identity_active_totp'] as $needle) contractRequire($migration, $needle);
foreach (['DROP CONSTRAINT ck_sand_iam_auth_challenge_identity', "CHECK (purpose = 'webauthn_auth' OR identity_id IS NOT NULL)", "SELECT '040_passkey_auth_challenge_identity.pgsql', 40"] as $needle) contractRequire($challengeIdentityMigration, $needle);
$challengeIdentityMigrationSource = file_get_contents($challengeIdentityMigration);
$castFirstNeedle = "lower(pg_get_constraintdef(actual.oid, true)),\n                    E'::[a-z_][a-z0-9_]*(\\\\s+varying)?'";
if (!is_string($challengeIdentityMigrationSource) || substr_count($challengeIdentityMigrationSource, $castFirstNeedle) !== 2) {
    contractFail('passkey challenge migration must remove PostgreSQL casts before whitespace');
}
$normalizedConstraint = preg_replace(
    '/::[a-z_][a-z0-9_]*(?:\s+varying)?/i',
    '',
    "CHECK (purpose::text = 'webauthn_auth'::text OR identity_id IS NOT NULL)",
);
$normalizedConstraint = preg_replace('/\s+/', '', (string) $normalizedConstraint);
$normalizedConstraint = str_replace(['(', ')'], '', (string) $normalizedConstraint);
if (strtolower($normalizedConstraint) !== "checkpurpose='webauthn_auth'oridentity_idisnotnull") {
    contractFail('PostgreSQL challenge constraint fixture does not preserve the OR identity predicate');
}
contractRequire($install, '-- IAM-T02-004 BEGIN');
contractRequire($pluginInstall, '-- IAM-T02-004 BEGIN');
if (file_get_contents($install) !== file_get_contents($pluginInstall)) contractFail('package root and plugin install payloads differ');
foreach (['/mfa/totp/start', '/mfa/challenge/verify', '/passkeys/registration/options', '/passkeys/authentication/finish'] as $needle) contractRequire($routes, $needle);
echo "mfa passkey contract checks passed\n";
