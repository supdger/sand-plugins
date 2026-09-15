<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

namespace KerberosDirectTlsTest {
    final class Fixture
    {
        public static array $metadata = ['crypto' => ['protocol' => 'TLSv1.3']];
        public static array $options = ['ssl' => ['local_cert' => '/fixture/server.pem']];
        public static bool $readable = true;
        public static bool $validCertificate = true;
        public static string $signature = 'sha256WithRSAEncryption';
        public static array $paths = [];
        public static array $reads = [];
        public static array $algorithms = [];
        public static bool $badDigest = false;
        public static bool $throwMetadata = false;
    }
    final class Connection
    {
        public string $transport = 'ssl';
        public string $ip = '192.0.2.9';
        public function __construct(public mixed $socket) {}
        public function getSocket(): mixed { return $this->socket; }
        public function getRemoteIp(): string { return $this->ip; }
    }
    function check(bool $value, string $message): void
    {
        if (!$value) throw new \RuntimeException($message);
    }
}

namespace plugin\sandadmin\exception {
    class ApiException extends \RuntimeException {}
}

namespace support {
    class Request
    {
        public function __construct(public mixed $connection) {}
        public function header(mixed ...$arguments): never
        {
            throw new \RuntimeException('Resolver must not read client headers');
        }
    }
}

namespace plugin\SandIam\app\kerberos {
    use KerberosDirectTlsTest\Fixture;

    // Only TLS/OpenSSL/file introspection is replaced; the production resolver
    // decides every boundary. No TLS server, certificate or private key is created.
    function stream_get_meta_data(mixed $socket): array
    {
        if (Fixture::$throwMetadata) throw new \RuntimeException('socket failure');
        return Fixture::$metadata;
    }
    function stream_context_get_options(mixed $socket): array { return Fixture::$options; }
    function is_file(string $path): bool { return $path === '/fixture/server.pem'; }
    function is_readable(string $path): bool { return Fixture::$readable; }
    function file_get_contents(string $path): string|false
    {
        Fixture::$paths[] = $path;
        return $path === '/fixture/server.pem' ? 'fixture certificate bytes' : false;
    }
    function openssl_x509_read(string $value): mixed
    {
        Fixture::$reads[] = $value;
        return Fixture::$validCertificate ? 'fixture-certificate' : false;
    }
    function openssl_x509_parse(mixed $certificate): array
    {
        return ['signatureTypeSN' => Fixture::$signature];
    }
    function openssl_x509_fingerprint(mixed $certificate, string $algorithm, bool $binary): string
    {
        Fixture::$algorithms[] = [$algorithm, $binary];
        return Fixture::$badDigest ? 'bad' : \hash($algorithm, 'fixture DER certificate', true);
    }
}

namespace {
    use KerberosDirectTlsTest\Connection;
    use KerberosDirectTlsTest\Fixture;
    use function KerberosDirectTlsTest\check;
    use plugin\SandIam\app\kerberos\DirectTlsSpnegoContextResolver;
    use plugin\sandadmin\exception\ApiException;
    use support\Request;

    require dirname(__DIR__) . '/app/kerberos/SpnegoContextResolver.php';
    require dirname(__DIR__) . '/app/kerberos/DirectTlsSpnegoContextResolver.php';
    $socket = fopen('php://memory', 'r');
    $connection = new Connection($socket);
    $request = new Request($connection);
    $resolver = new DirectTlsSpnegoContextResolver();
    foreach ([
        'sha256WithRSAEncryption' => 'sha256',
        'sha384WithRSAEncryption' => 'sha384',
        'ecdsa-with-SHA512' => 'sha512',
        'sha1WithRSAEncryption' => 'sha256',
        'md5WithRSAEncryption' => 'sha256',
    ] as $signature => $algorithm) {
        Fixture::$signature = $signature;
        $result = $resolver->resolve($request);
        check($result === [
            'channel_binding' => rtrim(strtr(base64_encode(hash($algorithm, 'fixture DER certificate', true)), '+/', '-_'), '='),
            'remote_ip' => '192.0.2.9',
        ], 'Wrong channel binding or peer IP');
        check(end(Fixture::$algorithms) === [$algorithm, true], 'Wrong RFC 5929 digest input');
        check(end(Fixture::$paths) === '/fixture/server.pem', 'Wrong server certificate path');
        check(end(Fixture::$reads) === 'fixture certificate bytes', 'Wrong server certificate source');
    }
    $reject = static function (callable $change, callable $restore, string $name) use ($resolver, $request): void {
        $change();
        try {
            $resolver->resolve($request);
            throw new \RuntimeException($name . ' accepted');
        } catch (ApiException $exception) {
            check($exception->getCode() === 503
                && $exception->getMessage() === 'SAND_IAM_KERBEROS_TRANSPORT_CONTEXT_UNAVAILABLE', $name . ' wrong rejection');
        } finally {
            $restore();
        }
    };
    $reject(fn () => $connection->transport = 'tcp', fn () => $connection->transport = 'ssl', 'plaintext');
    $reject(fn () => Fixture::$metadata = [], fn () => Fixture::$metadata = ['crypto' => ['protocol' => 'TLSv1.3']], 'unfinished TLS');
    $reject(fn () => Fixture::$options['ssl']['SNI_server_certs'] = ['example.test' => '/other.pem'],
        fn () => Fixture::$options['ssl'] = ['local_cert' => '/fixture/server.pem'], 'SNI ambiguity');
    foreach (['', 'relative.pem', 'https://example.test/cert', '/missing.pem'] as $path) {
        $reject(fn () => Fixture::$options['ssl']['local_cert'] = $path,
            fn () => Fixture::$options['ssl']['local_cert'] = '/fixture/server.pem', 'certificate path');
    }
    $reject(fn () => Fixture::$readable = false, fn () => Fixture::$readable = true, 'unreadable certificate');
    $reject(fn () => Fixture::$validCertificate = false, fn () => Fixture::$validCertificate = true, 'invalid certificate');
    $reject(fn () => Fixture::$signature = 'rsassaPss', fn () => Fixture::$signature = 'sha256WithRSAEncryption', 'unknown signature digest');
    $reject(fn () => Fixture::$badDigest = true, fn () => Fixture::$badDigest = false, 'invalid digest');
    $reject(fn () => $connection->ip = 'forged.example', fn () => $connection->ip = '192.0.2.9', 'invalid socket IP');
    $reject(fn () => Fixture::$throwMetadata = true, fn () => Fixture::$throwMetadata = false, 'socket exception');
    $reject(fn () => $request->connection = null, fn () => $request->connection = $connection, 'missing connection');
    $reject(fn () => $connection->socket = null, fn () => $connection->socket = $socket, 'missing socket');
    fclose($socket);
    echo "Direct TLS Kerberos context boundaries PASS (non-PG, TLS/OpenSSL introspection substitutes)\n";
}
