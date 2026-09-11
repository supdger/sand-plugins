<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\model\OidcSigningKey;
use plugin\sandadmin\exception\ApiException;
use think\facade\Db;

/** Issuer-scoped RS256 signing-key lifecycle; there is intentionally one signer. */
final class OidcSigningKeyService
{
    private const MAX_SIGNED_TOKEN_TTL = 900;

    public function __construct(
        private readonly OidcLogoutTokenCipher $cipher = new OidcLogoutTokenCipher(),
        private readonly AuditWriter $audit = new AuditWriter(),
    ) {
    }

    /** @return array<string,mixed> */
    public function rotate(string $actor, string $requestId, bool $manageTransaction = true): array
    {
        if ($manageTransaction) Db::startTrans();
        try {
            $active = OidcSigningKey::where('state', 'active')->lock(true)->select()->all();
            if (count($active) > 1) throw new ApiException('SAND_IAM_OIDC_SIGNING_KEY_CONFIGURATION_INVALID', 503);
            $now = $this->now();
            $graceUntil = date('Y-m-d H:i:s', time() + $this->graceSeconds());
            $this->retainLegacyDeploymentKey($graceUntil, $now);
            foreach ($active as $key) $key->save(['state' => 'verify_only', 'retire_time' => $graceUntil, 'update_time' => $now]);

            $material = $this->generate();
            $record = OidcSigningKey::create([
                'kid' => $material['kid'],
                'public_jwk' => $material['public_jwk'],
                'encrypted_private_key' => $this->cipher->encrypt($material['private_pem']),
                'encryption_version' => $this->encryptionVersion(),
                'algorithm' => 'RS256',
                'state' => 'active',
                'not_before_time' => $now,
                'retire_time' => null,
                'create_time' => $now,
                'update_time' => $now,
            ]);
            $this->audit->write('admin', $actor, null, null, 'oidc.signing_key.rotate', 'oidc_signing_key', (int) $record->id, 'succeeded', $requestId, ['kid' => (string) $record->kid, 'grace_until' => $graceUntil]);
            if ($manageTransaction) Db::commit();
            return $this->payload($record, $graceUntil);
        } catch (\Throwable $exception) {
            if ($manageTransaction) Db::rollback();
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    public function retire(int $id, string $actor, string $requestId, bool $manageTransaction = true): array
    {
        if ($manageTransaction) Db::startTrans();
        try {
            $record = OidcSigningKey::where('id', $id)->lock(true)->find();
            if ($record === null) throw new ApiException('SAND_IAM_OIDC_SIGNING_KEY_NOT_FOUND', 404);
            if ((string) $record->state === 'active') throw new ApiException('SAND_IAM_OIDC_SIGNING_KEY_ACTIVE_CANNOT_RETIRE', 409);
            if ((string) $record->state === 'retired') { if ($manageTransaction) Db::commit(); return $this->payload($record); }
            if ((string) $record->state !== 'verify_only' || $record->retire_time === null || strtotime((string) $record->retire_time) > time()) {
                throw new ApiException('SAND_IAM_OIDC_SIGNING_KEY_GRACE_NOT_ELAPSED', 409);
            }
            $record->save(['state' => 'retired', 'update_time' => $this->now()]);
            $this->audit->write('admin', $actor, null, null, 'oidc.signing_key.retire', 'oidc_signing_key', (int) $record->id, 'succeeded', $requestId, ['kid' => (string) $record->kid]);
            if ($manageTransaction) Db::commit();
            return $this->payload($record);
        } catch (\Throwable $exception) {
            if ($manageTransaction) Db::rollback();
            throw $exception;
        }
    }

    /** @return list<array<string,mixed>> */
    public function list(string $actor, string $requestId): array
    {
        $records = OidcSigningKey::order('id', 'desc')->select()->all();
        $result = array_map(fn (OidcSigningKey $record): array => $this->payload($record), $records);
        $this->audit->write('admin', $actor, null, null, 'oidc.signing_key.list', 'oidc_signing_key', null, 'succeeded', $requestId, ['count' => count($result)]);
        return $result;
    }

    /** @return array<string,mixed> */
    public function status(string $actor, string $requestId): array
    {
        $active = OidcSigningKey::where('state', 'active')->select()->all();
        if (count($active) > 1) throw new ApiException('SAND_IAM_OIDC_SIGNING_KEY_CONFIGURATION_INVALID', 503);
        $payload = ['owner_scope' => 'issuer', 'active_count' => count($active), 'active_key' => $active === [] ? null : $this->payload($active[0]), 'grace_seconds' => $this->graceSeconds(), 'legacy_deployment_key' => $active === []];
        $this->audit->write('admin', $actor, null, null, 'oidc.signing_key.status', 'oidc_signing_key', $active === [] ? null : (int) $active[0]->id, 'succeeded', $requestId, ['active_count' => count($active)]);
        return $payload;
    }

    private function retainLegacyDeploymentKey(string $graceUntil, string $now): void
    {
        $kid = (string) config('plugin.sand-iam.app.oidc_kid', '');
        $pem = base64_decode((string) config('plugin.sand-iam.app.oidc_private_key_base64', ''), true);
        if (!is_string($pem) || $pem === '' || preg_match('/^[A-Za-z0-9._-]{1,128}$/', $kid) !== 1) return;
        if (OidcSigningKey::where('kid', $kid)->lock(true)->find() !== null) return;
        $private = openssl_pkey_get_private($pem);
        $details = $private === false ? false : openssl_pkey_get_details($private);
        if (!is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || !isset($details['rsa']['n'], $details['rsa']['e'])) throw new ApiException('SAND_IAM_OIDC_SIGNING_KEY_CONFIGURATION_INVALID', 503);
        OidcSigningKey::create([
            'kid' => $kid,
            'public_jwk' => $this->publicJwk($kid, (string) $details['rsa']['n'], (string) $details['rsa']['e']),
            'encrypted_private_key' => null,
            'encryption_version' => null,
            'algorithm' => 'RS256',
            'state' => 'verify_only',
            'not_before_time' => $now,
            'retire_time' => $graceUntil,
            'create_time' => $now,
            'update_time' => $now,
        ]);
    }

    /** @return array{kid:string,private_pem:string,public_jwk:array<string,string>} */
    private function generate(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        if ($key === false || !openssl_pkey_export($key, $pem)) throw new ApiException('SAND_IAM_OIDC_SIGNING_KEY_UNAVAILABLE', 503);
        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || !isset($details['rsa']['n'], $details['rsa']['e'])) throw new ApiException('SAND_IAM_OIDC_SIGNING_KEY_UNAVAILABLE', 503);
        $kid = 'siam_oidc_' . bin2hex(random_bytes(16));
        return ['kid' => $kid, 'private_pem' => $pem, 'public_jwk' => $this->publicJwk($kid, (string) $details['rsa']['n'], (string) $details['rsa']['e'])];
    }

    /** @return array<string,string> */
    private function publicJwk(string $kid, string $modulus, string $exponent): array
    {
        return ['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $kid, 'n' => $this->b64($modulus), 'e' => $this->b64($exponent)];
    }

    /** @return array<string,mixed> */
    private function payload(OidcSigningKey $record, ?string $graceUntil = null): array
    {
        return ['id' => (int) $record->id, 'kid' => (string) $record->kid, 'algorithm' => (string) ($record->algorithm ?: 'RS256'), 'state' => (string) $record->state === 'verify_only' ? 'retiring' : (string) $record->state, 'not_before_time' => $record->not_before_time, 'grace_until' => $graceUntil ?? $record->retire_time, 'create_time' => $record->create_time, 'update_time' => $record->update_time];
    }

    private function graceSeconds(): int
    {
        $skew = max(0, min(3600, (int) config('plugin.sand-iam.app.oidc_signing_key_clock_skew_seconds', 300)));
        return max(self::MAX_SIGNED_TOKEN_TTL, OidcBackchannelLogoutService::MAX_DELIVERY_WINDOW) + $skew;
    }

    private function encryptionVersion(): string { return (string) config('plugin.sand-iam.app.oidc_logout_encryption_key_version', 'v1'); }
    private function now(): string { return date('Y-m-d H:i:s'); }
    private function b64(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
}
