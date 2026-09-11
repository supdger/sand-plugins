<?php

declare(strict_types=1);

namespace plugin\SandIam\app\webhook;

use plugin\sandadmin\exception\ApiException;

final class NativeWebhookHttpAdapter implements WebhookHttpAdapter
{
    public function post(string $url, array $headers, string $body, int $timeoutSeconds): array
    {
        [$host, $port, $addresses] = $this->destination($url);
        if (!function_exists('curl_init')) {
            throw new ApiException('SAND_IAM_WEBHOOK_TRANSPORT_UNAVAILABLE', 503);
        }
        $headerLines = ['Content-Type: application/json', 'Accept: application/json'];
        foreach ($headers as $name => $value) {
            if (!preg_match('/^[A-Za-z0-9-]{1,64}$/', $name) || preg_match('/[\r\n]/', $value)) {
                throw new ApiException('SAND_IAM_WEBHOOK_HEADER_INVALID', 500);
            }
            $headerLines[] = $name . ': ' . $value;
        }
        $response = '';
        $handle = curl_init($url);
        if ($handle === false) throw new ApiException('SAND_IAM_WEBHOOK_TRANSPORT_UNAVAILABLE', 503);
        try {
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headerLines,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROXY => '',
                CURLOPT_NOPROXY => '*',
                CURLOPT_CONNECTTIMEOUT => min(5, $timeoutSeconds),
                CURLOPT_TIMEOUT => $timeoutSeconds,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_RESOLVE => array_map(static fn (string $ip): string => $host . ':' . $port . ':' . $ip, $addresses),
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$response): int {
                    if (strlen($response) + strlen($chunk) > 65_536) return 0;
                    $response .= $chunk;
                    return strlen($chunk);
                },
            ]);
            if (curl_exec($handle) === false) {
                throw new ApiException('SAND_IAM_WEBHOOK_DELIVERY_FAILED', 503);
            }
            return ['status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => $response];
        } finally {
            curl_close($handle);
        }
    }

    /** @return array{0:string,1:int,2:list<string>} */
    private function destination(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new ApiException('SAND_IAM_WEBHOOK_URL_INVALID', 400);
        }
        $host = (string) $parts['host'];
        $port = (int) ($parts['port'] ?? 443);
        if ($port < 1 || $port > 65535) throw new ApiException('SAND_IAM_WEBHOOK_URL_INVALID', 400);
        $addresses = gethostbynamel($host);
        if (!is_array($addresses) || $addresses === []) throw new ApiException('SAND_IAM_WEBHOOK_DESTINATION_UNAVAILABLE', 503);
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new ApiException('SAND_IAM_WEBHOOK_DESTINATION_REJECTED', 400);
            }
        }
        return [$host, $port, array_values(array_unique($addresses))];
    }
}
