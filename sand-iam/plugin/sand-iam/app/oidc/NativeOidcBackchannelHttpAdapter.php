<?php

declare(strict_types=1);

namespace plugin\SandIam\app\oidc;

use plugin\SandIam\app\security\PublicDnsResolver;
use plugin\sandadmin\exception\ApiException;

final class NativeOidcBackchannelHttpAdapter implements OidcBackchannelHttpAdapter
{
    public function __construct(private readonly PublicDnsResolver $dns = new PublicDnsResolver()) {}

    public function postLogoutToken(string $url, string $body, int $timeoutSeconds): array
    {
        [$host, $port, $addresses] = $this->destination($url);
        if (!function_exists('curl_init')) throw new ApiException('SAND_IAM_OIDC_BACKCHANNEL_TRANSPORT_UNAVAILABLE', 503);
        $response = '';
        $handle = curl_init($url);
        if ($handle === false) throw new ApiException('SAND_IAM_OIDC_BACKCHANNEL_TRANSPORT_UNAVAILABLE', 503);
        try {
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: */*'],
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROXY => '',
                CURLOPT_NOPROXY => '*',
                CURLOPT_CONNECTTIMEOUT => min(5, $timeoutSeconds),
                CURLOPT_TIMEOUT => $timeoutSeconds,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_RESOLVE => array_map(static fn (string $ip): string => PublicDnsResolver::curlResolveEntry($host, $port, $ip), $addresses),
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$response): int {
                    if (strlen($response) + strlen($chunk) > 65_536) return 0;
                    $response .= $chunk;
                    return strlen($chunk);
                },
            ]);
            if (curl_exec($handle) === false) throw new ApiException('SAND_IAM_OIDC_BACKCHANNEL_DELIVERY_FAILED', 503);
            return ['status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => $response];
        } finally {
            curl_close($handle);
        }
    }

    /** @return array{0:string,1:int,2:list<string>} */
    private function destination(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) throw new ApiException('SAND_IAM_OIDC_BACKCHANNEL_URI_INVALID', 400);
        $host = trim((string) $parts['host'], '[]');
        $port = (int) ($parts['port'] ?? 443);
        if ($port < 1 || $port > 65535) throw new ApiException('SAND_IAM_OIDC_BACKCHANNEL_URI_INVALID', 400);
        $addresses = $this->dns->resolve($host, 'SAND_IAM_OIDC_BACKCHANNEL_DESTINATION_UNAVAILABLE', 'SAND_IAM_OIDC_BACKCHANNEL_DESTINATION_REJECTED');
        return [$host, $port, $addresses];
    }
}
