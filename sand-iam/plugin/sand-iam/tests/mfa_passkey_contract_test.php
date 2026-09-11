<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

function contractFail(string $message): never { fwrite(STDERR, "IAM-T02 contract failed: {$message}\n"); exit(1); }
function contractRequire(string $file, string $needle): void { $source = file_get_contents($file); if (!is_string($source) || !str_contains($source, $needle)) contractFail("{$file} is missing {$needle}"); }

$root = dirname(__DIR__, 3);
$service = $root . '/plugin/sand-iam/app/service/MfaService.php';
$migration = $root . '/migrations/004_mfa_passkey.pgsql';
$routes = $root . '/plugin/sand-iam/config/route.php';
$install = $root . '/install.sql';
$pluginInstall = $root . '/plugin/sand-iam/install.sql';
foreach (['assertCurrentPassword', 'assertMfaAttemptAllowed', 'mfa_encryption_keys', 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt', 'last_used_counter', 'SAND_IAM_MFA_TOTP_REPLAYED', 'webauthn.create', 'webauthn.get', 'openssl_verify', 'SAND_IAM_PASSKEY_BACKUP_STATE_INVALID', 'SAND_IAM_PASSKEY_SIGN_COUNT_REPLAYED', 'crossOrigin', 'userHandle'] as $needle) contractRequire($service, $needle);
foreach (['sand_iam_mfa_factor', 'sand_iam_mfa_recovery_code', 'sand_iam_webauthn_credential', 'sand_iam_auth_challenge', 'FOREIGN KEY (identity_id, application_id)', 'FOREIGN KEY (factor_id, application_id, identity_id)', 'uk_sand_iam_mfa_factor_identity_active_totp'] as $needle) contractRequire($migration, $needle);
contractRequire($install, '-- IAM-T02-004 BEGIN');
contractRequire($pluginInstall, '-- IAM-T02-004 BEGIN');
if (file_get_contents($install) !== file_get_contents($pluginInstall)) contractFail('package root and plugin install payloads differ');
foreach (['/mfa/totp/start', '/mfa/challenge/verify', '/passkeys/registration/options', '/passkeys/authentication/finish'] as $needle) contractRequire($routes, $needle);
echo "mfa passkey contract checks passed\n";
