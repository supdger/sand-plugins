<?php

declare(strict_types=1);

namespace plugin\SandIam\app\kerberos;

use plugin\sandadmin\exception\ApiException;

/** PECL krb5 with MIT Kerberos >= 1.19 and its persistent replay cache. */
final class PeclSpnegoVerifier implements SpnegoVerifier
{
    // MIT gssapi_ext.h; PECL returns this flag but does not export its constant.
    private const CHANNEL_BOUND_FLAG = 0x800;

    public function verify(string $token, array $config, array $context): array
    {
        if (!class_exists(\KRB5CCache::class) || !class_exists(\GSSAPIContext::class)
            || !class_exists(\GSSAPIChannelBinding::class)
            || !method_exists(\GSSAPIChannelBinding::class, 'setApplicationData')
            || !method_exists(\KRB5CCache::class, 'initKeytab')
            || !method_exists(\GSSAPIContext::class, 'acquireCredentials')
            || !method_exists(\GSSAPIContext::class, 'inquireCredentials')
            || !method_exists(\GSSAPIContext::class, 'acceptSecContext')
            || !defined('GSS_C_ACCEPT') || !defined('GSS_C_MUTUAL_FLAG') || !defined('GSS_C_REPLAY_FLAG')) {
            throw new ApiException('SAND_IAM_KERBEROS_VERIFIER_UNAVAILABLE', 503);
        }
        $keytabs = config('plugin.sand-iam.app.kerberos_keytabs', []);
        if (is_string($keytabs)) $keytabs = json_decode($keytabs, true);
        $reference = $config['keytab_ref'] ?? null;
        $path = is_array($keytabs) && is_string($reference) ? ($keytabs[$reference] ?? null) : null;
        $principal = $config['service_principal'] ?? null;
        if (!is_string($path) || !str_starts_with($path, '/') || str_contains($path, "\0")
            || !is_file($path) || !is_readable($path)
            || !is_string($principal) || preg_match('/\AHTTP\/[A-Za-z0-9.-]+@[A-Z0-9][A-Z0-9.-]{1,127}\z/', $principal) !== 1
            || ($config['require_channel_binding'] ?? null) !== true
            || ($config['require_replay_cache'] ?? null) !== true
            || ($config['require_mutual_auth'] ?? null) !== true
            || strtolower(trim((string) getenv('KRB5RCACHETYPE'))) === 'none') {
            throw new ApiException('SAND_IAM_KERBEROS_CONFIGURATION_INVALID', 503);
        }
        $bindingText = $context['channel_binding'] ?? '';
        $binding = is_string($bindingText) ? base64_decode(strtr($bindingText, '-_', '+/'), true) : false;
        $input = base64_decode($token, true);
        if ($input === false || $input === '' || strlen($token) > 65536
            || $binding === false || !in_array(strlen($binding), [32, 48, 64], true)
            || rtrim(strtr(base64_encode($binding), '+/', '-_'), '=') !== $bindingText) {
            throw new ApiException('SAND_IAM_KERBEROS_TOKEN_INVALID', 401);
        }
        try {
            // initKeytab acquires a TGT from the KDC. Deployment must bound KDC timeouts.
            $cache = new \KRB5CCache();
            if (@$cache->initKeytab($principal, $path) !== true) {
                throw new ApiException('SAND_IAM_KERBEROS_VERIFIER_UNAVAILABLE', 503);
            }
            $gss = new \GSSAPIContext();
            $gss->acquireCredentials($cache, $principal, GSS_C_ACCEPT);
            $credentials = $gss->inquireCredentials();
            if (!is_array($credentials) || ($credentials['name'] ?? null) !== $principal
                || ($credentials['cred_usage'] ?? null) !== 'accept'
                || !is_array($credentials['mechs'] ?? null) || $credentials['mechs'] === []) {
                throw new ApiException('SAND_IAM_KERBEROS_VERIFIER_UNAVAILABLE', 503);
            }
            foreach ($credentials['mechs'] as $mechanism) {
                // gss_oid_to_str uses braces and space-separated arcs.
                $oid = is_string($mechanism) ? preg_replace('/\s+/', ' ', trim($mechanism, " {}\t\r\n")) : null;
                if (!in_array($oid, ['1 2 840 113554 1 2 2', '1 3 6 1 5 5 2'], true)) {
                    throw new ApiException('SAND_IAM_KERBEROS_VERIFIER_UNAVAILABLE', 503);
                }
            }
            $channel = new \GSSAPIChannelBinding();
            $channel->setApplicationData('tls-server-end-point:' . $binding);
            $output = $source = '';
            $flags = $lifetime = 0;
            // PECL requires a cache object before the channel argument. Never persist it.
            $delegated = new \KRB5CCache();
            $complete = @$gss->acceptSecContext($input, $output, $source, $flags, $lifetime, $delegated, $channel);
            $required = GSS_C_MUTUAL_FLAG | GSS_C_REPLAY_FLAG | self::CHANNEL_BOUND_FLAG;
            if ($complete !== true || !is_int($flags) || ($flags & $required) !== $required
                || !is_int($lifetime) || $lifetime <= 0 || !is_string($source) || $source === ''
                || !is_string($output) || $output === '' || strlen($output) > 49152) {
                throw new ApiException('SAND_IAM_KERBEROS_VERIFICATION_INCOMPLETE', 401);
            }
            return ['principal' => $source, 'service_principal' => $principal,
                'mutual_auth' => true, 'channel_binding' => true, 'replay_protected' => true,
                'response_token' => base64_encode($output)];
        } catch (ApiException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new ApiException('SAND_IAM_KERBEROS_AUTHENTICATION_FAILED', 401);
        }
    }
}
