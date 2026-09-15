<?php

declare(strict_types=1);

namespace plugin\SandIam\app\kerberos;

use plugin\sandadmin\exception\ApiException;
use support\Request;

/**
 * Direct TLS only, with one immutable certificate file per listener.
 * Certificate rotation must use a new path and drain old TLS connections.
 * SNI certificate selection and reverse-proxy TLS require another resolver.
 */
final class DirectTlsSpnegoContextResolver implements SpnegoContextResolver
{
    public function resolve(Request $request): array
    {
        try {
            $connection = $request->connection ?? null;
            if (!is_object($connection) || ($connection->transport ?? '') !== 'ssl'
                || !method_exists($connection, 'getSocket') || !method_exists($connection, 'getRemoteIp')) {
                throw $this->unavailable();
            }
            $socket = $connection->getSocket();
            if (!is_resource($socket)) throw $this->unavailable();
            $metadata = stream_get_meta_data($socket);
            $crypto = $metadata['crypto'] ?? null;
            if (!is_array($crypto) || !is_string($crypto['protocol'] ?? null)
                || !str_starts_with($crypto['protocol'], 'TLS')) {
                throw $this->unavailable();
            }
            $options = stream_context_get_options($socket);
            $ssl = $options['ssl'] ?? null;
            if (!is_array($ssl) || !empty($ssl['SNI_server_certs'])) throw $this->unavailable();
            $path = $ssl['local_cert'] ?? null;
            if (!is_string($path) || !str_starts_with($path, '/') || str_contains($path, "\0")
                || !is_file($path) || !is_readable($path)) {
                throw $this->unavailable();
            }
            $certificateBytes = @file_get_contents($path);
            if (!is_string($certificateBytes) || $certificateBytes === '') throw $this->unavailable();
            $certificate = @openssl_x509_read($certificateBytes);
            if ($certificate === false) throw $this->unavailable();
            $details = openssl_x509_parse($certificate);
            // RFC 5929 §4: MD5/SHA-1 certificate signatures use SHA-256.
            // Unknown algorithms (including parameterized RSA-PSS) fail closed.
            $algorithm = match ($details['signatureTypeSN'] ?? '') {
                'md5WithRSAEncryption', 'sha1WithRSAEncryption', 'ecdsa-with-SHA1', 'DSA-SHA1',
                'sha256WithRSAEncryption', 'ecdsa-with-SHA256', 'dsa_with_SHA256' => 'sha256',
                'sha384WithRSAEncryption', 'ecdsa-with-SHA384' => 'sha384',
                'sha512WithRSAEncryption', 'ecdsa-with-SHA512' => 'sha512',
                default => throw $this->unavailable(),
            };
            $digest = openssl_x509_fingerprint($certificate, $algorithm, true);
            if (!is_string($digest) || strlen($digest) !== match ($algorithm) {
                'sha256' => 32, 'sha384' => 48, 'sha512' => 64,
            }) throw $this->unavailable();
            $remoteIp = $connection->getRemoteIp();
            if (!is_string($remoteIp) || filter_var($remoteIp, FILTER_VALIDATE_IP) === false) {
                throw $this->unavailable();
            }
            return [
                'channel_binding' => rtrim(strtr(base64_encode($digest), '+/', '-_'), '='),
                'remote_ip' => $remoteIp,
            ];
        } catch (\Throwable $exception) {
            if ($exception instanceof ApiException) throw $exception;
            throw $this->unavailable();
        }
    }

    private function unavailable(): ApiException
    {
        return new ApiException('SAND_IAM_KERBEROS_TRANSPORT_CONTEXT_UNAVAILABLE', 503);
    }
}
