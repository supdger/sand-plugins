<?php

declare(strict_types=1);

namespace plugin\SandIam\app\federation;

use plugin\SandIam\app\security\PublicDnsResolver;
use plugin\sandadmin\exception\ApiException;

final class NativeFederationHttpAdapter implements FederationHttpAdapter
{
    public function __construct(private readonly PublicDnsResolver $dns = new PublicDnsResolver()) {}

    public function json(string $method, string $url, array $headers = [], array $form = []): array
    {
        [$host, $port, $addresses] = $this->destination($url);
        if (!function_exists('curl_init')) throw new ApiException('SAND_IAM_FEDERATION_HTTP_UNAVAILABLE', 503);
        $body = $form === [] ? '' : http_build_query($form, '', '&', PHP_QUERY_RFC3986);
        $headerLines = ['Accept: application/json'];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || !is_string($value) || preg_match('/[\r\n]/', $name . $value)) {
                throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID', 400);
            }
            $headerLines[] = $name . ': ' . $value;
        }
        if ($body !== '') $headerLines[] = 'Content-Type: application/x-www-form-urlencoded';
        $response = '';
        $handle = curl_init($url);
        if ($handle === false) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_UNAVAILABLE', 503);
        try {
            curl_setopt_array($handle, [
                CURLOPT_CUSTOMREQUEST => strtoupper($method), CURLOPT_HTTPHEADER => $headerLines,
                CURLOPT_POSTFIELDS => $body === '' ? null : $body, CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
                CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                // Pin the DNS result selected above; this avoids resolution
                // changing between validation and the outgoing connection.
                CURLOPT_RESOLVE => array_map(static fn (string $ip): string => PublicDnsResolver::curlResolveEntry($host, $port, $ip), $addresses),
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$response): int {
                    if (strlen($response) + strlen($chunk) > 1_048_576) return 0;
                    $response .= $chunk;
                    return strlen($chunk);
                },
            ]);
            if (curl_exec($handle) === false) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_UNAVAILABLE', 503);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $type = (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
            if ($status < 200 || $status >= 300 || !str_starts_with(strtolower($type), 'application/json')) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_RESPONSE_INVALID', 502);
        } finally { curl_close($handle); }
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_RESPONSE_INVALID', 502);
        return $decoded;
    }

    /** @return array{0:string,1:int,2:list<string>} */
    private function destination(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID', 400);
        }
        $host = trim((string) $parts['host'], '[]');
        $port = (int) ($parts['port'] ?? 443);
        if ($port < 1 || $port > 65535) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID', 400);
        return [$host, $port, $this->dns->resolve($host, 'SAND_IAM_FEDERATION_PROVIDER_UNAVAILABLE', 'SAND_IAM_FEDERATION_PROVIDER_ADDRESS_REJECTED')];
    }
}
