<?php

declare(strict_types=1);

final class MachineServiceHttpClient
{
    /** @param array<string,mixed> $body @param array<string,string> $headers @return array<string,mixed> */
    public static function postJson(string $url, array $body, array $headers = []): array
    {
        self::assertUrl($url);
        $headerLines = ['Content-Type: application/json', 'Cache-Control: no-store'];
        foreach ($headers as $name => $value) {
            if (!preg_match('/^[A-Za-z0-9-]+$/', $name) || str_contains($value, "\r") || str_contains($value, "\n")) {
                throw new InvalidArgumentException('SandIAM 请求头包含非法字符');
            }
            $headerLines[] = $name . ': ' . $value;
        }
        $response = file_get_contents($url, false, stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headerLines) . "\r\n",
            'content' => json_encode($body, JSON_THROW_ON_ERROR),
            'ignore_errors' => true,
            'timeout' => 10,
        ]]));
        if ($response === false) {
            throw new RuntimeException('SandIAM 网络请求失败');
        }
        return self::decode($response, self::responseStatus($http_response_header ?? []));
    }

    /** @return array<string,mixed> */
    public static function decode(string $response, int $status): array
    {
        try {
            $decoded = json_decode($response, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('SandIAM 返回了无法解析的数据', 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('SandIAM 返回了无法识别的数据');
        }
        if ($status < 200 || $status >= 300) {
            $message = is_string($decoded['msg'] ?? null) ? $decoded['msg'] : (is_string($decoded['message'] ?? null) ? $decoded['message'] : 'SAND_IAM_REQUEST_FAILED');
            preg_match('/^(SAND_IAM_[A-Z0-9_]+)/', $message, $matches);
            throw new RuntimeException(($matches[1] ?? 'SAND_IAM_REQUEST_FAILED') . ' (HTTP ' . $status . ')');
        }
        $data = $decoded['data'] ?? null;
        if (!is_array($data) || array_is_list($data)) {
            throw new RuntimeException('SandIAM 返回的数据结构不正确');
        }
        return $data;
    }

    /** @param list<string> $headers */
    private static function responseStatus(array $headers): int
    {
        $status = 0;
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})(?:\s|$)/i', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }
        return $status;
    }

    private static function assertUrl(string $url): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $localHttp = $scheme === 'http' && in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
        if ($scheme !== 'https' && !$localHttp) {
            throw new InvalidArgumentException('SandIAM 地址必须使用 HTTPS；仅本机开发允许 HTTP');
        }
    }
}
