<?php

declare(strict_types=1);

namespace plugin\SandIam\bin;

/**
 * Offline, secret-safe validation of deployment-owned SandIAM settings.
 *
 * This class deliberately does not load the host, connect to PostgreSQL, or
 * test an external endpoint. It validates only values explicitly supplied by
 * the caller and reports configuration key names without echoing their values.
 */
final class RuntimeConfigurationPreflight
{
    /** @var list<string> */
    private const SWITCHES = [
        'SAND_IAM_DEBUG',
        'SAND_IAM_APPLICATION_EXPERIENCE_ENABLED',
        'SAND_IAM_MESSAGE_PROVIDER_ENABLED',
        'SAND_IAM_IDENTITY_LIFECYCLE_ENABLED',
        'SAND_IAM_ACCEPTANCE_FIXTURE_CLEANUP_ENABLED',
        'SAND_IAM_IDENTITY_EVENT_OUTBOX_ENABLED',
        'SAND_IAM_AUDIT_EVENT_OUTBOX_ENABLED',
        'SAND_IAM_APPLICATION_NETWORK_POLICY_ENABLED',
        'SAND_IAM_SECURITY_ALERT_ENABLED',
        'SAND_IAM_AUDIT_PURGE_ENABLED',
        'SAND_IAM_OAUTH_DYNAMIC_REGISTRATION_ENABLED',
        'SAND_IAM_OIDC_FRONTCHANNEL_LOGOUT_ENABLED',
        'SAND_IAM_OIDC_BACKCHANNEL_LOGOUT_ENABLED',
        'SAND_IAM_CAS_ENABLED',
        'SAND_IAM_KERBEROS_ENABLED',
        'SAND_IAM_RADIUS_SERVER_ENABLED',
        'SAND_IAM_WEBHOOK_WORKER_ENABLED',
        'SAND_IAM_OIDC_LOGOUT_WORKER_ENABLED',
        'SAND_IAM_RADIUS_WORKER_ENABLED',
        'SAND_IAM_AUDIT_ARCHIVE_WORKER_ENABLED',
        'SAND_IAM_SECURITY_OPERATION_RETENTION_WORKER_ENABLED',
        'SAND_IAM_DIRECTORY_SYNC_WORKER_ENABLED',
    ];

    /** @var list<string> */
    private const ENCRYPTION_KEYS = [
        'SAND_IAM_MESSAGE_ENCRYPTION_KEY',
        'SAND_IAM_INVITATION_ENCRYPTION_KEY',
        'SAND_IAM_IMPORT_ENCRYPTION_KEY',
        'SAND_IAM_SYNC_ENCRYPTION_KEY',
        'SAND_IAM_OIDC_LOGOUT_ENCRYPTION_KEY',
        'SAND_IAM_RADIUS_ENCRYPTION_KEY',
        'SAND_IAM_MFA_ENCRYPTION_KEY',
        'SAND_IAM_FEDERATION_ENCRYPTION_KEY',
        'SAND_IAM_WEBHOOK_ENCRYPTION_KEY',
    ];

    /** @var list<string> */
    private const KEY_VERSIONS = [
        'SAND_IAM_AUTH_PEPPER_VERSION',
        'SAND_IAM_MESSAGE_ENCRYPTION_KEY_VERSION',
        'SAND_IAM_INVITATION_ENCRYPTION_KEY_VERSION',
        'SAND_IAM_IMPORT_ENCRYPTION_KEY_VERSION',
        'SAND_IAM_SYNC_ENCRYPTION_KEY_VERSION',
        'SAND_IAM_OIDC_LOGOUT_ENCRYPTION_KEY_VERSION',
        'SAND_IAM_RADIUS_ENCRYPTION_KEY_VERSION',
        'SAND_IAM_MFA_ENCRYPTION_KEY_VERSION',
        'SAND_IAM_WEBHOOK_ENCRYPTION_KEY_VERSION',
    ];

    /** @var list<string> */
    private const KEYRINGS = [
        'SAND_IAM_MESSAGE_ENCRYPTION_KEYS',
        'SAND_IAM_INVITATION_ENCRYPTION_KEYS',
        'SAND_IAM_IMPORT_ENCRYPTION_KEYS',
        'SAND_IAM_SYNC_ENCRYPTION_KEYS',
        'SAND_IAM_OIDC_LOGOUT_ENCRYPTION_KEYS',
        'SAND_IAM_RADIUS_ENCRYPTION_KEYS',
        'SAND_IAM_MFA_ENCRYPTION_KEYS',
        'SAND_IAM_WEBHOOK_ENCRYPTION_KEYS',
    ];

    /** @var list<string> */
    private const DRIVER_MAPS = [
        'SAND_IAM_MESSAGE_DRIVERS',
        'SAND_IAM_SYNC_DRIVERS',
    ];

    /** @var list<string> */
    private const CLASS_NAMES = [
        'SAND_IAM_AUTH_EMAIL_SENDER',
        'SAND_IAM_AUTH_PHONE_SENDER',
        'SAND_IAM_KERBEROS_VERIFIER',
        'SAND_IAM_KERBEROS_CONTEXT_RESOLVER',
    ];

    /** @var array<string,array{0:int,1:int}> */
    private const INTEGER_RANGES = [
        'SAND_IAM_DIRECTORY_SYNC_WORKER_INTERVAL_SECONDS' => [1, PHP_INT_MAX],
        'SAND_IAM_DIRECTORY_SYNC_WORKER_BATCH_SIZE' => [1, 100],
        'SAND_IAM_DIRECTORY_SYNC_WORKER_RETRY_BASE_SECONDS' => [1, PHP_INT_MAX],
        'SAND_IAM_DIRECTORY_SYNC_WORKER_RETRY_MAX_SECONDS' => [1, PHP_INT_MAX],
        'SAND_IAM_SYNC_OUTBOX_MAX_ATTEMPTS' => [1, 100],
        'SAND_IAM_AUDIT_ARCHIVE_INTERVAL_SECONDS' => [300, PHP_INT_MAX],
        'SAND_IAM_AUDIT_ARCHIVE_BATCH_SIZE' => [1, 1000],
        'SAND_IAM_SECURITY_OPERATION_RETENTION_DAYS' => [30, 3650],
        'SAND_IAM_SECURITY_OPERATION_RETENTION_INTERVAL_SECONDS' => [300, PHP_INT_MAX],
        'SAND_IAM_SECURITY_OPERATION_RETENTION_BATCH_SIZE' => [1, 1000],
        'SAND_IAM_AUTH_RATE_LIMIT_RETENTION_HOURS' => [1, 168],
        'SAND_IAM_OIDC_LOGOUT_POLL_INTERVAL_MS' => [250, PHP_INT_MAX],
        'SAND_IAM_OIDC_LOGOUT_BATCH_SIZE' => [1, 100],
        'SAND_IAM_RADIUS_AUTH_PORT' => [1, 65535],
        'SAND_IAM_RADIUS_ACCOUNTING_PORT' => [1, 65535],
        'SAND_IAM_OIDC_SIGNING_KEY_CLOCK_SKEW_SECONDS' => [0, 3600],
        'SAND_IAM_WEBHOOK_POLL_INTERVAL_MS' => [250, PHP_INT_MAX],
        'SAND_IAM_WEBHOOK_BATCH_SIZE' => [1, 100],
    ];

    /** @return array{schema:string,profile:string,scope:string,checked_keys:int,passed:bool,errors:list<array{code:string,key:string,message:string}>,warnings:list<array{code:string,key:string,message:string}>} */
    public function inspect(array $environment, string $profile = 'release'): array
    {
        if (!in_array($profile, ['release', 'acceptance'], true)) {
            throw new \InvalidArgumentException('Unsupported preflight profile');
        }

        $values = [];
        foreach ($this->knownKeys() as $key) {
            if (array_key_exists($key, $environment) && is_scalar($environment[$key])) {
                $values[$key] = (string) $environment[$key];
            }
        }

        $errors = [];
        $warnings = [];
        foreach (self::SWITCHES as $key) {
            if (isset($values[$key]) && !in_array($values[$key], ['0', '1'], true)) {
                $this->issue($errors, 'INVALID_SWITCH', $key, 'must be exactly 0 or 1');
            }
        }
        if ($profile === 'release') {
            foreach (['SAND_IAM_DEBUG', 'SAND_IAM_ACCEPTANCE_FIXTURE_CLEANUP_ENABLED'] as $key) {
                if (($values[$key] ?? '0') === '1') {
                    $this->issue($errors, 'RELEASE_UNSAFE_SWITCH', $key, 'must be 0 in the release profile');
                }
            }
        }

        foreach (self::ENCRYPTION_KEYS as $key) {
            if (($values[$key] ?? '') !== '' && !$this->isBase64Key($values[$key])) {
                $this->issue($errors, 'INVALID_ENCRYPTION_KEY', $key, 'must be standard base64 encoding exactly 32 bytes');
            }
        }
        foreach (self::KEY_VERSIONS as $key) {
            if (($values[$key] ?? '') !== '' && !$this->isVersion($values[$key])) {
                $this->issue($errors, 'INVALID_KEY_VERSION', $key, 'must use 1-32 letters, digits, dot, underscore, or hyphen');
            }
        }
        foreach (self::KEYRINGS as $key) {
            if (($values[$key] ?? '') !== '') {
                $this->validateKeyring($errors, $key, $values[$key]);
            }
        }
        foreach (self::DRIVER_MAPS as $key) {
            if (($values[$key] ?? '') !== '') {
                $this->validateStringMap($errors, $key, $values[$key]);
            }
        }
        foreach (self::CLASS_NAMES as $key) {
            if (($values[$key] ?? '') !== '' && preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $values[$key]) !== 1) {
                $this->issue($errors, 'INVALID_CLASS_NAME', $key, 'must be a PHP class name; class availability is checked only after the host loads');
            }
        }
        foreach (self::INTEGER_RANGES as $key => [$minimum, $maximum]) {
            if (($values[$key] ?? '') === '') continue;
            $raw = $values[$key];
            $parsed = filter_var($raw, FILTER_VALIDATE_INT);
            if ($parsed === false || $parsed < $minimum || $parsed > $maximum) {
                $this->issue($errors, 'INTEGER_OUT_OF_RANGE', $key, 'must be an integer in the documented safe range');
            }
        }

        if (($values['SAND_IAM_OIDC_ISSUER'] ?? '') !== '' && !$this->isOidcIssuer($values['SAND_IAM_OIDC_ISSUER'])) {
            $this->issue($errors, 'INVALID_OIDC_ISSUER', 'SAND_IAM_OIDC_ISSUER', 'must be an HTTPS URL ending in /api/sand-iam/v1 without credentials');
        }
        if (($values['SAND_IAM_INVITATION_ACCEPT_URL'] ?? '') !== '' && !$this->isHttpsUrl($values['SAND_IAM_INVITATION_ACCEPT_URL'])) {
            $this->issue($errors, 'INVALID_INVITATION_URL', 'SAND_IAM_INVITATION_ACCEPT_URL', 'must be an HTTPS URL without credentials');
        }
        if (($values['SAND_IAM_RADIUS_BIND_HOST'] ?? '') !== '' && filter_var($values['SAND_IAM_RADIUS_BIND_HOST'], FILTER_VALIDATE_IP) === false) {
            $this->issue($errors, 'INVALID_BIND_HOST', 'SAND_IAM_RADIUS_BIND_HOST', 'must be an IPv4 or IPv6 address');
        }

        $this->requireSwitch($errors, $values, 'SAND_IAM_DIRECTORY_SYNC_WORKER_ENABLED', 'SAND_IAM_IDENTITY_LIFECYCLE_ENABLED');
        $this->requireSwitch($errors, $values, 'SAND_IAM_OIDC_LOGOUT_WORKER_ENABLED', 'SAND_IAM_OIDC_BACKCHANNEL_LOGOUT_ENABLED');
        $this->requireSwitch($errors, $values, 'SAND_IAM_RADIUS_WORKER_ENABLED', 'SAND_IAM_RADIUS_SERVER_ENABLED');
        $this->requireSwitch($errors, $values, 'SAND_IAM_RADIUS_SERVER_ENABLED', 'SAND_IAM_RADIUS_WORKER_ENABLED');
        $this->requireValue($errors, $values, 'SAND_IAM_MESSAGE_PROVIDER_ENABLED', 'SAND_IAM_MESSAGE_ENCRYPTION_KEY');
        $this->requireValue($errors, $values, 'SAND_IAM_SECURITY_ALERT_ENABLED', 'SAND_IAM_SECURITY_ALERT_FINGERPRINT_KEY');
        $this->requireValue($errors, $values, 'SAND_IAM_WEBHOOK_WORKER_ENABLED', 'SAND_IAM_WEBHOOK_ENCRYPTION_KEY');
        $this->requireValue($errors, $values, 'SAND_IAM_OIDC_LOGOUT_WORKER_ENABLED', 'SAND_IAM_OIDC_LOGOUT_ENCRYPTION_KEY');
        $this->requireValue($errors, $values, 'SAND_IAM_RADIUS_SERVER_ENABLED', 'SAND_IAM_RADIUS_ENCRYPTION_KEY');
        $this->requireValue($errors, $values, 'SAND_IAM_RADIUS_SERVER_ENABLED', 'SAND_IAM_RADIUS_REPLAY_KEY');
        $this->requireValue($errors, $values, 'SAND_IAM_KERBEROS_ENABLED', 'SAND_IAM_KERBEROS_VERIFIER');
        $this->requireValue($errors, $values, 'SAND_IAM_KERBEROS_ENABLED', 'SAND_IAM_KERBEROS_CONTEXT_RESOLVER');

        if (($values['SAND_IAM_DIRECTORY_SYNC_WORKER_RETRY_BASE_SECONDS'] ?? '') !== ''
            && ($values['SAND_IAM_DIRECTORY_SYNC_WORKER_RETRY_MAX_SECONDS'] ?? '') !== ''
            && (int) $values['SAND_IAM_DIRECTORY_SYNC_WORKER_RETRY_MAX_SECONDS'] < (int) $values['SAND_IAM_DIRECTORY_SYNC_WORKER_RETRY_BASE_SECONDS']) {
            $this->issue($errors, 'INVALID_RETRY_WINDOW', 'SAND_IAM_DIRECTORY_SYNC_WORKER_RETRY_MAX_SECONDS', 'must not be lower than the retry base');
        }
        if (($values['SAND_IAM_RADIUS_AUTH_PORT'] ?? '') !== ''
            && $values['SAND_IAM_RADIUS_AUTH_PORT'] === ($values['SAND_IAM_RADIUS_ACCOUNTING_PORT'] ?? null)) {
            $this->issue($errors, 'DUPLICATE_RADIUS_PORT', 'SAND_IAM_RADIUS_ACCOUNTING_PORT', 'must differ from the authentication port');
        }

        $this->validateLegacyOidcKey($errors, $values);

        foreach ([
            'SAND_IAM_CONTEXT_SIGNING_KEY', 'SAND_IAM_AUTH_PEPPER', 'SAND_IAM_INVITATION_TOKEN_PEPPER',
            'SAND_IAM_GUEST_REFERENCE_PEPPER', 'SAND_IAM_SYNC_REFERENCE_PEPPER', 'SAND_IAM_OIDC_SUBJECT_KEY',
            'SAND_IAM_SECURITY_ALERT_FINGERPRINT_KEY', 'SAND_IAM_RADIUS_REPLAY_KEY',
        ] as $key) {
            if (($values[$key] ?? '') !== '' && strlen($values[$key]) < 32) {
                if ($profile === 'release') {
                    $this->issue($errors, 'SHORT_SECRET', $key, 'is shorter than the documented 32-byte minimum');
                } else {
                    $this->issue($warnings, 'SHORT_SECRET', $key, 'is shorter than the documented 32-byte minimum');
                }
            }
        }

        return [
            'schema' => 'sand-iam.runtime-configuration-preflight/v1',
            'profile' => $profile,
            'scope' => 'configuration-shape-only',
            'checked_keys' => count($values),
            'passed' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /** @return list<string> */
    private function knownKeys(): array
    {
        return array_values(array_unique(array_merge(
            self::SWITCHES,
            self::ENCRYPTION_KEYS,
            self::KEY_VERSIONS,
            self::KEYRINGS,
            self::DRIVER_MAPS,
            self::CLASS_NAMES,
            array_keys(self::INTEGER_RANGES),
            [
                'SAND_IAM_OIDC_ISSUER', 'SAND_IAM_INVITATION_ACCEPT_URL', 'SAND_IAM_RADIUS_BIND_HOST',
                'SAND_IAM_CONTEXT_SIGNING_KEY', 'SAND_IAM_AUTH_PEPPER', 'SAND_IAM_INVITATION_TOKEN_PEPPER',
                'SAND_IAM_GUEST_REFERENCE_PEPPER', 'SAND_IAM_SYNC_REFERENCE_PEPPER', 'SAND_IAM_OIDC_SUBJECT_KEY',
                'SAND_IAM_SECURITY_ALERT_FINGERPRINT_KEY', 'SAND_IAM_RADIUS_REPLAY_KEY',
                'SAND_IAM_OIDC_PRIVATE_KEY_BASE64', 'SAND_IAM_OIDC_KID',
            ],
        )));
    }

    /** @param list<array{code:string,key:string,message:string}> $issues */
    private function issue(array &$issues, string $code, string $key, string $message): void
    {
        $issues[] = ['code' => $code, 'key' => $key, 'message' => $message];
    }

    private function isBase64Key(string $value): bool
    {
        $decoded = base64_decode($value, true);
        return is_string($decoded) && strlen($decoded) === 32 && base64_encode($decoded) === $value;
    }

    private function isVersion(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9._-]{1,32}$/D', $value) === 1;
    }

    /** @param list<array{code:string,key:string,message:string}> $errors */
    private function validateKeyring(array &$errors, string $key, string $raw): void
    {
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->issue($errors, 'INVALID_KEYRING', $key, 'must be a JSON object of version to base64 32-byte key');
            return;
        }
        if (!is_array($decoded) || array_is_list($decoded) || $decoded === []) {
            $this->issue($errors, 'INVALID_KEYRING', $key, 'must be a non-empty JSON object');
            return;
        }
        foreach ($decoded as $version => $encoded) {
            if (!is_string($version) || !$this->isVersion($version) || !is_string($encoded) || !$this->isBase64Key($encoded)) {
                $this->issue($errors, 'INVALID_KEYRING_ENTRY', $key, 'contains an invalid version or key entry');
                return;
            }
        }
    }

    /** @param list<array{code:string,key:string,message:string}> $errors */
    private function validateStringMap(array &$errors, string $key, string $raw): void
    {
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->issue($errors, 'INVALID_DRIVER_MAP', $key, 'must be a JSON object of code to class name');
            return;
        }
        if (!is_array($decoded) || array_is_list($decoded) || $decoded === []) {
            $this->issue($errors, 'INVALID_DRIVER_MAP', $key, 'must be a non-empty JSON object');
            return;
        }
        foreach ($decoded as $code => $class) {
            if (!is_string($code) || preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $code) !== 1 || !is_string($class) || trim($class) === '') {
                $this->issue($errors, 'INVALID_DRIVER_MAP_ENTRY', $key, 'contains an invalid driver code or class name');
                return;
            }
        }
    }

    private function isHttpsUrl(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_URL) === false
            || strtolower((string) parse_url($value, PHP_URL_SCHEME)) !== 'https'
            || parse_url($value, PHP_URL_USER) !== null
            || parse_url($value, PHP_URL_PASS) !== null) {
            return false;
        }
        return true;
    }

    private function isOidcIssuer(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false
            && parse_url($value, PHP_URL_QUERY) === null
            && parse_url($value, PHP_URL_FRAGMENT) === null
            && preg_match('#^https://[A-Za-z0-9.-]+(?::[0-9]{1,5})?/api/sand-iam/v1/?$#D', $value) === 1;
    }

    /** @param list<array{code:string,key:string,message:string}> $errors @param array<string,string> $values */
    private function validateLegacyOidcKey(array &$errors, array $values): void
    {
        $encoded = $values['SAND_IAM_OIDC_PRIVATE_KEY_BASE64'] ?? '';
        $kid = $values['SAND_IAM_OIDC_KID'] ?? '';
        if ($encoded === '' && $kid === '') return;
        if ($encoded === '') {
            $this->issue($errors, 'MISSING_OIDC_PRIVATE_KEY', 'SAND_IAM_OIDC_PRIVATE_KEY_BASE64', 'must accompany SAND_IAM_OIDC_KID');
            return;
        }
        if (preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $kid) !== 1) {
            $this->issue($errors, 'INVALID_OIDC_KID', 'SAND_IAM_OIDC_KID', 'must accompany the legacy key and use 1-128 safe characters');
        }
        if (!function_exists('openssl_pkey_get_private') || !function_exists('openssl_pkey_get_details')) {
            $this->issue($errors, 'OPENSSL_UNAVAILABLE', 'SAND_IAM_OIDC_PRIVATE_KEY_BASE64', 'requires the OpenSSL extension for offline key validation');
            return;
        }
        $pem = base64_decode($encoded, true);
        $private = is_string($pem) ? openssl_pkey_get_private($pem) : false;
        $details = $private === false ? false : openssl_pkey_get_details($private);
        if (!is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || (int) ($details['bits'] ?? 0) < 2048) {
            $this->issue($errors, 'INVALID_OIDC_PRIVATE_KEY', 'SAND_IAM_OIDC_PRIVATE_KEY_BASE64', 'must encode an RSA private PEM of at least 2048 bits');
        }
    }

    /** @param list<array{code:string,key:string,message:string}> $errors @param array<string,string> $values */
    private function requireSwitch(array &$errors, array $values, string $enabledKey, string $requiredKey): void
    {
        if (($values[$enabledKey] ?? '0') === '1' && ($values[$requiredKey] ?? '0') !== '1') {
            $this->issue($errors, 'MISSING_REQUIRED_SWITCH', $requiredKey, 'must be 1 when ' . $enabledKey . ' is enabled');
        }
    }

    /** @param list<array{code:string,key:string,message:string}> $errors @param array<string,string> $values */
    private function requireValue(array &$errors, array $values, string $enabledKey, string $requiredKey): void
    {
        if (($values[$enabledKey] ?? '0') === '1' && trim($values[$requiredKey] ?? '') === '') {
            $this->issue($errors, 'MISSING_REQUIRED_VALUE', $requiredKey, 'must be configured when ' . $enabledKey . ' is enabled');
        }
    }
}
