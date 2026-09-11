<?php

declare(strict_types=1);

namespace plugin\SandIam\app\federation;

use plugin\sandadmin\exception\ApiException;

/**
 * The concrete adapter deliberately refuses clear-text LDAP.  It is small on
 * purpose: the provider-specific query is frozen in configuration, while sync
 * state and identity ownership remain in FederationService.
 */
final class NativeLdapDirectoryAdapter implements LdapDirectoryAdapter
{
    public function page(array $config, ?string $cursor): array
    {
        if (!function_exists('ldap_connect')) throw new ApiException('SAND_IAM_LDAP_EXTENSION_UNAVAILABLE', 503);
        $uri = (string) ($config['uri'] ?? '');
        if (!preg_match('#^ldaps://([^/?#:]+)(?::([0-9]{1,5}))?$#', $uri, $uriMatch) || (isset($uriMatch[2]) && ((int) $uriMatch[2] < 1 || (int) $uriMatch[2] > 65535))) throw new ApiException('SAND_IAM_LDAP_TLS_REQUIRED', 400);
        $baseDn = trim((string) ($config['base_dn'] ?? ''));
        $filter = trim((string) ($config['filter'] ?? ''));
        $bindDn = trim((string) ($config['bind_dn'] ?? ''));
        $bindPassword = (string) ($config['bind_password'] ?? '');
        if ($baseDn === '' || $filter === '' || $bindDn === '' || $bindPassword === '') throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID', 400);
        if (!defined('LDAP_CONTROL_PAGEDRESULTS')) throw new ApiException('SAND_IAM_LDAP_PAGING_UNAVAILABLE', 503);
        // These are process defaults used by LDAP's TLS context. Demand a
        // verified server certificate before the first connection is opened.
        if (!@ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_DEMAND)) throw new ApiException('SAND_IAM_LDAP_TLS_REQUIRED', 503);
        $connection = @ldap_connect($uri);
        if ($connection === false) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_UNAVAILABLE', 503);
        try {
            ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);
            if (!@ldap_set_option($connection, LDAP_OPT_NETWORK_TIMEOUT, min(max((int) ($config['network_timeout_seconds'] ?? 10), 1), 60))) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_UNAVAILABLE', 503);
            if (!@ldap_bind($connection, $bindDn, $bindPassword)) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_UNAVAILABLE', 503);
            $out = [];
            $pageSize = min(max((int) ($config['page_size'] ?? 100), 1), 500);
            $maxEntries = min(max((int) ($config['max_entries'] ?? 10_000), 1), 100_000);
            $attributes = $config['attributes'] ?? [];
            if (!is_array($attributes) || $attributes === []) throw new ApiException('SAND_IAM_DIRECTORY_MAPPING_INVALID', 400);
            $attributes = array_values(array_unique(array_filter($attributes, static fn (mixed $name): bool => is_string($name) && preg_match('/^[A-Za-z][A-Za-z0-9-]{0,127}$/', $name))));
            if ($attributes === []) throw new ApiException('SAND_IAM_DIRECTORY_MAPPING_INVALID', 400);
            // RFC2696 cookies are valid only for this one connection/search.
            // Never accept or return a persisted cookie as a sync checkpoint.
            if ($cursor !== null && $cursor !== '') throw new ApiException('SAND_IAM_DIRECTORY_CURSOR_INVALID', 400);
            $cookie = '';
            $pages = 0;
            $started = microtime(true);
            $seenCookies = [];
            do {
                if (++$pages > 10_000) throw new ApiException('SAND_IAM_DIRECTORY_SYNC_INCOMPLETE', 503);
                if (microtime(true) - $started > min(max((int) ($config['max_sync_seconds'] ?? 300), 1), 900)) throw new ApiException('SAND_IAM_DIRECTORY_SYNC_INCOMPLETE', 503);
                $controls = [[
                    'oid' => LDAP_CONTROL_PAGEDRESULTS,
                    'iscritical' => true,
                    'value' => ['size' => $pageSize, 'cookie' => $cookie],
                ]];
                $search = @ldap_search($connection, $baseDn, $filter, $attributes, 0, 0, min(max((int) ($config['search_timeout_seconds'] ?? 30), 1), 120), LDAP_DEREF_NEVER, $controls);
                if ($search === false) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_UNAVAILABLE', 503);
                $entries = ldap_get_entries($connection, $search);
                for ($index = 0; $index < (int) ($entries['count'] ?? 0); $index++) {
                    $entry = $entries[$index];
                    $normalized = [];
                    foreach ($entry as $key => $value) {
                        if (!is_string($key) || $key === 'count' || !is_array($value) || !isset($value[0])) continue;
                        $normalized[strtolower($key)] = (string) $value[0];
                    }
                    if ($normalized !== []) $out[] = $normalized;
                    if (count($out) > $maxEntries) throw new ApiException('SAND_IAM_DIRECTORY_SYNC_INCOMPLETE', 503);
                }
                $responseControls = [];
                if (!@ldap_parse_result($connection, $search, $error, $matchedDn, $message, $references, $responseControls) || $error !== 0) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_UNAVAILABLE', 503);
                $cookie = '';
                $pagedControl = false;
                foreach ($responseControls as $control) if (($control['oid'] ?? '') === LDAP_CONTROL_PAGEDRESULTS) { $pagedControl = true; $cookie = (string) (($control['value']['cookie'] ?? '')); }
                if (!$pagedControl) throw new ApiException('SAND_IAM_LDAP_PAGING_UNAVAILABLE', 503);
                if ($cookie !== '') { $key = base64_encode($cookie); if (isset($seenCookies[$key])) throw new ApiException('SAND_IAM_DIRECTORY_SYNC_INCOMPLETE', 503); $seenCookies[$key] = true; }
            } while ($cookie !== '');
            return ['entries' => $out, 'cursor' => null, 'complete' => true];
        } finally {
            ldap_unbind($connection);
        }
    }
}
