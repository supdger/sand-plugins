<?php

declare(strict_types=1);

use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\AuthPolicy;
use plugin\SandIam\app\model\WebauthnCredential;
use plugin\SandIam\app\model\Organization;
use plugin\SandIam\app\service\HumanAuthService;
use plugin\SandIam\app\service\MfaService;
use plugin\sandadmin\exception\ApiException;

$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server';
$sandIamRoot = dirname(__DIR__, 3);
chdir($hostRoot);
require $hostRoot . '/vendor/autoload.php';
require $sandIamRoot . '/plugin/sand-iam/app/functions.php';
\Webman\Config::clear();
support\App::loadAllConfig(['route']);
\Webman\Config::load($sandIamRoot . '/plugin/sand-iam/config', ['route'], 'plugin.sand-iam');
\Webman\ThinkOrm\ThinkOrm::start(null);

function t02Fail(string $message): never { fwrite(STDERR, "IAM-T02 integration failed: {$message}\n"); exit(1); }
function t02Assert(bool $condition, string $message): void { if (!$condition) t02Fail($message); }
function t02Expect(callable $callable, string $code): void { try { $callable(); } catch (ApiException $e) { if (str_contains($e->getMessage(), $code)) return; t02Fail("expected {$code}, got {$e->getMessage()}"); } t02Fail("expected {$code}, but it succeeded"); }
function t02B64(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
function t02B64d(string $value): string { $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true); if ($decoded === false) t02Fail('invalid base64url fixture'); return $decoded; }
function t02Hotp(string $secret, int $counter): string { $mac = hash_hmac('sha1', pack('N2', 0, $counter), $secret, true); $offset = ord($mac[19]) & 15; $v = ((ord($mac[$offset]) & 127) << 24) | (ord($mac[$offset + 1]) << 16) | (ord($mac[$offset + 2]) << 8) | ord($mac[$offset + 3]); return str_pad((string) ($v % 1000000), 6, '0', STR_PAD_LEFT); }
function t02Base32Decode(string $value): string { $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; foreach (str_split($value) as $char) $bits .= str_pad(decbin(strpos($alphabet, $char)), 5, '0', STR_PAD_LEFT); $out = ''; foreach (str_split($bits, 8) as $part) if (strlen($part) === 8) $out .= chr(bindec($part)); return $out; }
function t02CborHead(int $major, int $value): string { if ($value < 24) return chr(($major << 5) | $value); if ($value <= 0xff) return chr(($major << 5) | 24) . chr($value); if ($value <= 0xffff) return chr(($major << 5) | 25) . pack('n', $value); return chr(($major << 5) | 26) . pack('N', $value); }
function t02CborInt(int $value): string { return $value >= 0 ? t02CborHead(0, $value) : t02CborHead(1, -1 - $value); }
function t02CborBytes(string $value): string { return t02CborHead(2, strlen($value)) . $value; }
function t02CborText(string $value): string { return t02CborHead(3, strlen($value)) . $value; }
/** @param array<int|string,mixed> $map */
function t02CborValue(mixed $value): string { if (is_int($value)) return t02CborInt($value); if (is_string($value)) return t02CborBytes($value); if (is_array($value)) return t02CborMap($value); t02Fail('unsupported CBOR fixture type'); }
function t02CborMap(array $map): string { $out = t02CborHead(5, count($map)); foreach ($map as $key => $value) { $out .= is_int($key) ? t02CborInt($key) : t02CborText($key); $out .= t02CborValue($value); } return $out; }
function t02ClientData(string $type, string $challenge, string $origin): string { return t02B64(json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin, 'crossOrigin' => false], JSON_UNESCAPED_SLASHES)); }
function t02AuthData(string $rp, int $counter, bool $attested = false, string $credential = '', string $cose = '', ?int $flags = null): string { $value = hash('sha256', $rp, true) . chr($flags ?? ($attested ? 0x45 : 0x05)) . pack('N', $counter); return $attested ? $value . str_repeat("\0", 16) . pack('n', strlen($credential)) . $credential . $cose : $value; }
/** @return array<string,mixed> */
function t02Assertion(array $options, string $rp, string $credential, \OpenSSLAsymmetricKey $key, int $count, string $userHandle, string $origin = 'https://app-a.example.test', bool $badSignature = false, int $flags = 0x05): array { $client = t02ClientData('webauthn.get', $options['public_key']['challenge'], $origin); $auth = t02AuthData($rp, $count, false, '', '', $flags); openssl_sign($auth . hash('sha256', t02B64d($client), true), $signature, $key, OPENSSL_ALGO_SHA256); if ($badSignature) $signature[0] = chr(ord($signature[0]) ^ 1); return ['challenge_token' => $options['challenge_token'], 'rawId' => t02B64($credential), 'response' => ['clientDataJSON' => $client, 'authenticatorData' => t02B64($auth), 'signature' => t02B64($signature), 'userHandle' => $userHandle]]; }

$auth = new HumanAuthService();
$mfa = new MfaService();
$legacyKey = base64_decode('ZmVkY2JhOTg3NjU0MzIxMGZlZGNiYTk4NzY1NDMyMTA=', true);
$legacyNonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
$legacyPlain = 'legacy-key-ring-fixture';
$legacyWire = 'legacy-v0.' . t02B64($legacyNonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($legacyPlain, 'sand-iam:mfa:legacy-v0', $legacyNonce, $legacyKey));
$decryptMethod = new ReflectionMethod(MfaService::class, 'decrypt');
t02Assert($decryptMethod->invoke($mfa, $legacyWire) === $legacyPlain, 'retained MFA key version could not decrypt a legacy record during rotation');
$org = Organization::create(['code' => 't02-runtime-org', 'name' => 'T02 runtime organization', 'status' => 1]);
$appA = Application::create(['organization_id' => (int) $org->id, 'code' => 'app-a-t02', 'name' => 'T02 Passkey A', 'status' => 1]);
$appB = Application::create(['organization_id' => (int) $org->id, 'code' => 'app-b-t02', 'name' => 'T02 Passkey B', 'status' => 1]);
AuthPolicy::create(['application_id' => (int) $appA->id, 'registration_enabled' => 1, 'webauthn_rp_id' => 'app-a.example.test', 'webauthn_allowed_origins' => json_encode(['https://app-a.example.test']), 'webauthn_user_verification' => 'required', 'status' => 1]);
AuthPolicy::create(['application_id' => (int) $appB->id, 'registration_enabled' => 1, 'webauthn_rp_id' => 'app-b.example.test', 'webauthn_allowed_origins' => json_encode(['https://app-b.example.test']), 'webauthn_user_verification' => 'required', 'status' => 1]);
$refA = ['organization_code' => 't02-runtime-org', 'application_code' => 'app-a-t02'];
$refB = ['organization_code' => 't02-runtime-org', 'application_code' => 'app-b-t02'];
$password = 'T02!StrongPassword';
$registered = $auth->register($refA + ['username' => 't02-user', 'display_name' => 'T02 user', 'email' => 't02@example.test', 'password' => $password], '127.0.0.1', 't02-register');
$token = (string) $registered['access_token'];

t02Expect(static fn () => $mfa->totpStart($token, '攻击者验证器', 'wrong-password', 't02-totp-step-up-rejected', '127.0.0.1'), 'SAND_IAM_AUTH_CURRENT_PASSWORD_INVALID');
$totpStart = $mfa->totpStart($token, '我的验证器', $password, 't02-totp-start', '127.0.0.1');
$secret = t02Base32Decode((string) $totpStart['secret']);
$counter = intdiv(time(), 30) - 1;
$confirmed = $mfa->totpConfirm($token, (int) $totpStart['factor_id'], t02Hotp($secret, $counter), 't02-totp-confirm');
t02Assert(count($confirmed['recovery_codes']) === 10, 'TOTP confirmation did not return ten one-time recovery codes');
t02Expect(static fn () => $mfa->totpConfirm($token, (int) $totpStart['factor_id'], t02Hotp($secret, $counter), 't02-totp-replay'), 'SAND_IAM_MFA_TOTP_REPLAYED');

$passwordLogin = $auth->login($refA + ['identifier' => 't02@example.test', 'password' => $password], '127.0.0.2', 't02-password-login');
t02Assert(($passwordLogin['mfa_required'] ?? false) === true && !isset($passwordLogin['access_token']), 'password login issued a session without MFA');
t02Expect(static fn () => $mfa->verifyLoginChallenge($refB + ['challenge_token' => $passwordLogin['challenge_token'], 'method' => 'totp', 'code' => t02Hotp($secret, intdiv(time(), 30))], '127.0.0.2', 't02-cross-app-challenge'), 'SAND_IAM_MFA_CHALLENGE_INVALID');
$mfaTokens = $mfa->verifyLoginChallenge($refA + ['challenge_token' => $passwordLogin['challenge_token'], 'method' => 'totp', 'code' => t02Hotp($secret, intdiv(time(), 30))], '127.0.0.2', 't02-mfa-totp');
t02Assert(isset($mfaTokens['access_token'], $mfaTokens['refresh_token']), 'TOTP MFA did not issue a session');

$recovery = (string) $confirmed['recovery_codes'][0];
$recoveryLogin = $auth->login($refA + ['identifier' => 't02@example.test', 'password' => $password], '127.0.0.3', 't02-recovery-login');
$mfa->verifyLoginChallenge($refA + ['challenge_token' => $recoveryLogin['challenge_token'], 'method' => 'recovery_code', 'code' => $recovery], '127.0.0.3', 't02-recovery-use');
$recoveryReplay = $auth->login($refA + ['identifier' => 't02@example.test', 'password' => $password], '127.0.0.3', 't02-recovery-replay-login');
t02Expect(static fn () => $mfa->verifyLoginChallenge($refA + ['challenge_token' => $recoveryReplay['challenge_token'], 'method' => 'recovery_code', 'code' => $recovery], '127.0.0.3', 't02-recovery-replay'), 'SAND_IAM_MFA_RECOVERY_CODE_INVALID');

$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
t02Assert($key !== false, 'could not create ES256 test key');
$details = openssl_pkey_get_details($key);
$x = $details['ec']['x']; $y = $details['ec']['y']; $credential = random_bytes(32);
t02Expect(static fn () => $mfa->passkeyRegistrationOptions($token, '攻击者通行密钥', 'wrong-password', 't02-passkey-step-up-rejected', '127.0.0.1'), 'SAND_IAM_AUTH_CURRENT_PASSWORD_INVALID');
$registration = $mfa->passkeyRegistrationOptions($token, 'MacBook 通行密钥', $password, 't02-passkey-options', '127.0.0.1');
$cose = t02CborMap([1 => 2, 3 => -7, -1 => 1, -2 => $x, -3 => $y]);
$attestation = t02CborMap(['fmt' => 'none', 'authData' => t02AuthData('app-a.example.test', 1, true, $credential, $cose), 'attStmt' => []]);
$mfa->passkeyRegistrationFinish($token, ['challenge_token' => $registration['challenge_token'], 'rawId' => t02B64($credential), 'response' => ['clientDataJSON' => t02ClientData('webauthn.create', $registration['public_key']['challenge'], 'https://app-a.example.test'), 'attestationObject' => t02B64($attestation)]], 't02-passkey-finish', '127.0.0.1');
$factor = WebauthnCredential::where('application_id', (int) $appA->id)->where('credential_id', t02B64($credential))->find();
t02Assert($factor !== null && (int) $factor->sign_count === 1, 'real ES256 attestation was not persisted');
$userHandle = (string) $factor->user_handle;

$badOrigin = $mfa->passkeyAuthenticationOptions($refA, 't02-bad-origin-options');
t02Expect(static fn () => $mfa->passkeyAuthenticationFinish($refA + t02Assertion($badOrigin, 'app-a.example.test', $credential, $key, 2, $userHandle, 'https://evil.example.test'), '127.0.0.4', 't02-bad-origin'), 'SAND_IAM_PASSKEY_CLIENT_DATA_INVALID');
$badRp = $mfa->passkeyAuthenticationOptions($refA, 't02-bad-rp-options');
t02Expect(static fn () => $mfa->passkeyAuthenticationFinish($refA + t02Assertion($badRp, 'wrong.example.test', $credential, $key, 2, $userHandle), '127.0.0.4', 't02-bad-rp'), 'SAND_IAM_PASSKEY_RP_ID_MISMATCH');
$badSignature = $mfa->passkeyAuthenticationOptions($refA, 't02-bad-signature-options');
t02Expect(static fn () => $mfa->passkeyAuthenticationFinish($refA + t02Assertion($badSignature, 'app-a.example.test', $credential, $key, 2, $userHandle, 'https://app-a.example.test', true), '127.0.0.4', 't02-bad-signature'), 'SAND_IAM_PASSKEY_SIGNATURE_INVALID');
$badUserHandle = $mfa->passkeyAuthenticationOptions($refA, 't02-bad-user-handle-options');
t02Expect(static fn () => $mfa->passkeyAuthenticationFinish($refA + t02Assertion($badUserHandle, 'app-a.example.test', $credential, $key, 2, t02B64(random_bytes(32))), '127.0.0.4', 't02-bad-user-handle'), 'SAND_IAM_PASSKEY_USER_HANDLE_INVALID');
$badBackupState = $mfa->passkeyAuthenticationOptions($refA, 't02-bad-backup-state-options');
t02Expect(static fn () => $mfa->passkeyAuthenticationFinish($refA + t02Assertion($badBackupState, 'app-a.example.test', $credential, $key, 2, $userHandle, 'https://app-a.example.test', false, 0x15), '127.0.0.4', 't02-bad-backup-state'), 'SAND_IAM_PASSKEY_BACKUP_STATE_INVALID');
$assertionOptions = $mfa->passkeyAuthenticationOptions($refA, 't02-passkey-auth-options');
$passkeyTokens = $mfa->passkeyAuthenticationFinish($refA + t02Assertion($assertionOptions, 'app-a.example.test', $credential, $key, 2, $userHandle, 'https://app-a.example.test', false, 0x0d), '127.0.0.4', 't02-passkey-auth');
t02Assert(isset($passkeyTokens['access_token']), 'passkey passwordless flow did not issue session');
$backupAssertion = $mfa->passkeyAuthenticationOptions($refA, 't02-passkey-backup-options');
$mfa->passkeyAuthenticationFinish($refA + t02Assertion($backupAssertion, 'app-a.example.test', $credential, $key, 3, $userHandle, 'https://app-a.example.test', false, 0x1d), '127.0.0.4', 't02-passkey-backup');
$signReplay = $mfa->passkeyAuthenticationOptions($refA, 't02-sign-count-options');
t02Expect(static fn () => $mfa->passkeyAuthenticationFinish($refA + t02Assertion($signReplay, 'app-a.example.test', $credential, $key, 3, $userHandle), '127.0.0.4', 't02-sign-count-replay'), 'SAND_IAM_PASSKEY_SIGN_COUNT_REPLAYED');
$crossCredential = $mfa->passkeyAuthenticationOptions($refB, 't02-cross-credential-options');
t02Expect(static fn () => $mfa->passkeyAuthenticationFinish($refB + t02Assertion($crossCredential, 'app-b.example.test', $credential, $key, 4, $userHandle, 'https://app-b.example.test'), '127.0.0.4', 't02-cross-credential'), 'SAND_IAM_PASSKEY_CREDENTIAL_NOT_FOUND');

$policyB = AuthPolicy::where('application_id', (int) $appB->id)->find();
t02Assert($policyB !== null, 'passkey rate-limit policy is missing');
$policyB->save(['rate_limit_per_minute' => 2]);
$mfa->passkeyAuthenticationOptions($refB, 't02-passkey-rate-one', '127.0.0.88');
$mfa->passkeyAuthenticationOptions($refB, 't02-passkey-rate-two', '127.0.0.88');
t02Expect(static fn () => $mfa->passkeyAuthenticationOptions($refB, 't02-passkey-rate-three', '127.0.0.88'), 'SAND_IAM_AUTH_RATE_LIMITED');

$org->save(['status' => 2]);
t02Expect(static fn () => $auth->sessions((string) $passkeyTokens['access_token']), 'SAND_IAM_AUTHENTICATION_FAILED');
t02Expect(static fn () => $auth->refresh((string) $passkeyTokens['refresh_token'], '127.0.0.4', 't02-refresh-disabled-organization'), 'SAND_IAM_AUTHENTICATION_FAILED');

echo "mfa passkey service integration passed\n";
