<?php

declare(strict_types=1);

namespace plugin\SandIam\app\sync;

use plugin\SandIam\app\security\PublicDnsResolver;
use plugin\sandadmin\exception\ApiException;

/** HTTPS-only, DNS-pinned directory transport with redirects and proxies disabled. */
final class NativeRemoteDirectoryTransport implements RemoteDirectoryTransport
{
    public function __construct(private readonly PublicDnsResolver $dns = new PublicDnsResolver()) {}

    /** @param array<string,string> $headers @return array{status:int,headers:array<string,string>,body:string} */
    public function get(string $url, array $headers): array
    {
        [$host, $port, $addresses] = $this->destination($url);
        if (!function_exists('curl_init')) throw new ApiException('SAND_IAM_SYNC_REMOTE_TRANSPORT_UNAVAILABLE', 503);
        $headerLines = ['Accept: application/json'];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || !is_string($value) || preg_match('/[\r\n]/', $name . $value)) throw new ApiException('SAND_IAM_SYNC_CONFIGURATION_INVALID', 400);
            $headerLines[] = $name . ': ' . $value;
        }
        $body = ''; $responseHeaders = [];
        $handle = curl_init($url);
        if ($handle === false) throw new ApiException('SAND_IAM_SYNC_REMOTE_TRANSPORT_UNAVAILABLE', 503);
        try {
            curl_setopt_array($handle, [
                CURLOPT_HTTPGET => true, CURLOPT_HTTPHEADER => $headerLines, CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
                CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_RESOLVE => array_map(static fn (string $ip): string => PublicDnsResolver::curlResolveEntry($host, $port, $ip), $addresses),
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                    $separator = strpos($line, ':');
                    if ($separator !== false) {
                        $name = strtolower(trim(substr($line, 0, $separator)));
                        $value = trim(substr($line, $separator + 1));
                        if ($name !== '' && strlen($value) <= 1024) $responseHeaders[$name] = $value;
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 2_097_152) return 0;
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            if (curl_exec($handle) === false) throw new ApiException('SAND_IAM_SYNC_REMOTE_TRANSPORT_UNAVAILABLE', 503);
            return ['status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'headers' => $responseHeaders, 'body' => $body];
        } finally {
            curl_close($handle);
        }
    }

    /** @return array{0:string,1:int,2:list<string>} */
    private function destination(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) throw new ApiException('SAND_IAM_SYNC_REMOTE_ADDRESS_REJECTED', 400);
        $host = trim((string) $parts['host'], '[]'); $port = (int) ($parts['port'] ?? 443);
        if ($port < 1 || $port > 65535) throw new ApiException('SAND_IAM_SYNC_REMOTE_ADDRESS_REJECTED', 400);
        return [$host, $port, $this->dns->resolve($host, 'SAND_IAM_SYNC_REMOTE_TRANSPORT_UNAVAILABLE', 'SAND_IAM_SYNC_REMOTE_ADDRESS_REJECTED')];
    }
}
