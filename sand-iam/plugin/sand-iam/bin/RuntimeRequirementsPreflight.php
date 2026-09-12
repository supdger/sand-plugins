<?php

declare(strict_types=1);

namespace plugin\SandIam\bin;

/** Offline capability check for a complete SandIAM installation. */
final class RuntimeRequirementsPreflight
{
    public const MINIMUM_PHP = '8.2.0';

    /** @var array<string,string> */
    private const CAPABILITIES = [
        'ctype' => 'credential and identifier validation',
        'curl' => 'federation, remote directory, OIDC logout, and webhook HTTPS clients',
        'dom' => 'SAML XML parsing and signature verification',
        'json' => 'API, policy, event, and configuration payloads',
        'ldap' => 'LDAPS directory interoperability',
        'libxml' => 'hardened SAML XML processing',
        'mbstring' => 'Unicode identity and display-name validation',
        'openssl' => 'OIDC, Passkey, and SAML asymmetric cryptography',
        'pdo' => 'database access',
        'pdo_pgsql' => 'the only supported database driver',
        'sodium' => 'MFA, provider, invitation, sync, RADIUS, and webhook secret encryption',
        'zip' => 'SandPackage ZIP installation and package verification',
        'zlib' => 'SAML HTTP-Redirect message compression',
    ];

    /**
     * @param array<string,bool> $capabilities
     * @return array{schema:string,scope:string,php_version:string,minimum_php:string,passed:bool,checked:int,errors:list<array{code:string,requirement:string,purpose:string}>}
     */
    public function inspect(string $phpVersion, array $capabilities): array
    {
        $errors = [];
        if (version_compare($phpVersion, self::MINIMUM_PHP, '<')) {
            $errors[] = [
                'code' => 'PHP_VERSION_UNSUPPORTED',
                'requirement' => 'php>=' . self::MINIMUM_PHP,
                'purpose' => 'SandIAM runtime',
            ];
        }
        foreach (self::CAPABILITIES as $name => $purpose) {
            if (($capabilities[$name] ?? false) !== true) {
                $errors[] = [
                    'code' => 'PHP_CAPABILITY_MISSING',
                    'requirement' => 'ext-' . $name,
                    'purpose' => $purpose,
                ];
            }
        }
        return [
            'schema' => 'sand-iam.runtime-requirements-preflight/v1',
            'scope' => 'complete-plugin-runtime-shape-only',
            'php_version' => $phpVersion,
            'minimum_php' => self::MINIMUM_PHP,
            'passed' => $errors === [],
            'checked' => count(self::CAPABILITIES) + 1,
            'errors' => $errors,
        ];
    }

    /** @return array<string,bool> */
    public static function detect(): array
    {
        $drivers = class_exists(\PDO::class) ? \PDO::getAvailableDrivers() : [];
        return [
            'ctype' => function_exists('ctype_digit'),
            'curl' => function_exists('curl_init'),
            'dom' => class_exists(\DOMDocument::class),
            'json' => function_exists('json_decode') && function_exists('json_encode'),
            'ldap' => function_exists('ldap_connect'),
            'libxml' => function_exists('libxml_use_internal_errors'),
            'mbstring' => function_exists('mb_strlen') && function_exists('mb_check_encoding'),
            'openssl' => function_exists('openssl_verify') && function_exists('openssl_pkey_get_private'),
            'pdo' => class_exists(\PDO::class),
            'pdo_pgsql' => in_array('pgsql', $drivers, true),
            'sodium' => function_exists('sodium_crypto_secretbox')
                && function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt'),
            'zip' => class_exists(\ZipArchive::class),
            'zlib' => function_exists('gzdeflate') && function_exists('gzinflate'),
        ];
    }
}
