<?php

declare(strict_types=1);

namespace KerberosPeclVerifierTest {
    final class State
    {
        public static array $keytabs = [];
        public static array $configCalls = [];
    }

    function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }
}

namespace plugin\sandadmin\exception {
    class ApiException extends \RuntimeException {}
}

namespace {
    use KerberosPeclVerifierTest\State;

    defined('GSS_C_ACCEPT') || define('GSS_C_ACCEPT', 2);
    defined('GSS_C_MUTUAL_FLAG') || define('GSS_C_MUTUAL_FLAG', 4);
    defined('GSS_C_REPLAY_FLAG') || define('GSS_C_REPLAY_FLAG', 8);

    function config(string $key, mixed $default = null): mixed
    {
        State::$configCalls[] = $key;
        return $key === 'plugin.sand-iam.app.kerberos_keytabs' ? State::$keytabs : $default;
    }

    final class KRB5CCache
    {
        public static array $instances = [];
        public static bool $initResult = true;
        public array $initCalls = [];

        public function __construct()
        {
            self::$instances[] = $this;
        }

        public function initKeytab(string $principal, string $path): bool
        {
            $this->initCalls[] = [$principal, $path];
            return self::$initResult;
        }
    }

    final class GSSAPIChannelBinding
    {
        public static array $instances = [];
        public array $applicationData = [];

        public function __construct()
        {
            self::$instances[] = $this;
        }

        public function setApplicationData(string $value): void
        {
            $this->applicationData[] = $value;
        }
    }

    final class GSSAPIContext
    {
        public static array $instances = [];
        public static array $credentials = [];
        public static bool $complete = true;
        public static int $flags = 0;
        public static int $lifetime = 300;
        public static string $source = 'alice@EXAMPLE.COM';
        public static string $output = 'server-response';
        public static ?string $throwMessage = null;
        public array $acquireCalls = [];
        public array $acceptCalls = [];

        public function __construct()
        {
            self::$instances[] = $this;
        }

        public function acquireCredentials(KRB5CCache $cache, string $principal, int $usage): void
        {
            $this->acquireCalls[] = [$cache, $principal, $usage];
        }

        public function inquireCredentials(): array
        {
            return self::$credentials;
        }

        public function acceptSecContext(
            string $input,
            string &$output,
            string &$source,
            int &$flags,
            int &$lifetime,
            KRB5CCache $delegated,
            GSSAPIChannelBinding $channel,
        ): bool {
            $this->acceptCalls[] = [$input, $delegated, $channel];
            if (self::$throwMessage !== null) {
                throw new \RuntimeException(self::$throwMessage);
            }
            $output = self::$output;
            $source = self::$source;
            $flags = self::$flags;
            $lifetime = self::$lifetime;
            return self::$complete;
        }
    }
}

namespace {
    use KerberosPeclVerifierTest\State;
    use function KerberosPeclVerifierTest\check;
    use plugin\SandIam\app\kerberos\PeclSpnegoVerifier;
    use plugin\sandadmin\exception\ApiException;

    require dirname(__DIR__) . '/app/kerberos/SpnegoVerifier.php';
    require dirname(__DIR__) . '/app/kerberos/PeclSpnegoVerifier.php';

    $principal = 'HTTP/iam.example.test@EXAMPLE.COM';
    $reference = 'deployment-keytab-v1';
    $rawToken = "\x60\x82raw-spnego-token";
    $bindingBytes = str_repeat("\xA5", 32);
    $bindingText = rtrim(strtr(base64_encode($bindingBytes), '+/', '-_'), '=');
    $config = [
        'service_principal' => $principal,
        'keytab_ref' => $reference,
        'require_channel_binding' => true,
        'require_replay_cache' => true,
        'require_mutual_auth' => true,
    ];
    $context = ['channel_binding' => $bindingText, 'remote_ip' => '192.0.2.20'];
    $verifier = new PeclSpnegoVerifier();
    $requiredFlags = GSS_C_MUTUAL_FLAG | GSS_C_REPLAY_FLAG | 0x800;

    $reset = static function () use ($principal, $requiredFlags): void {
        State::$keytabs = ['deployment-keytab-v1' => __FILE__];
        State::$configCalls = [];
        KRB5CCache::$instances = [];
        KRB5CCache::$initResult = true;
        GSSAPIChannelBinding::$instances = [];
        GSSAPIContext::$instances = [];
        GSSAPIContext::$credentials = [
            'name' => $principal,
            'cred_usage' => 'accept',
            'mechs' => ['{ 1 2 840 113554 1 2 2 }', " 1 3 6 1 5 5 2\n"],
        ];
        GSSAPIContext::$complete = true;
        GSSAPIContext::$flags = $requiredFlags;
        GSSAPIContext::$lifetime = 300;
        GSSAPIContext::$source = 'alice@EXAMPLE.COM';
        GSSAPIContext::$output = 'server-response';
        GSSAPIContext::$throwMessage = null;
    };
    $verify = static fn (): array => $verifier->verify(base64_encode($rawToken), $config, $context);
    $rejects = static function (callable $operation, string $message, int $status): void {
        try {
            $operation();
            throw new \RuntimeException("accepted invalid {$message}");
        } catch (ApiException $exception) {
            check($exception->getMessage() === $message, "wrong error for {$message}");
            check($exception->getCode() === $status, "wrong status for {$message}");
        }
    };

    $reset();
    $result = $verify();
    check($result === [
        'principal' => 'alice@EXAMPLE.COM',
        'service_principal' => $principal,
        'mutual_auth' => true,
        'channel_binding' => true,
        'replay_protected' => true,
        'response_token' => base64_encode('server-response'),
    ], 'successful verification returned the wrong contract');
    check(State::$configCalls === ['plugin.sand-iam.app.kerberos_keytabs'], 'deployment keytab map was not used');
    check(count(KRB5CCache::$instances) === 2, 'acceptance did not create credential and delegated caches');
    check(KRB5CCache::$instances[0]->initCalls === [[$principal, __FILE__]], 'keytab reference or full SPN reached initKeytab');
    $gss = GSSAPIContext::$instances[0];
    check($gss->acquireCalls === [[KRB5CCache::$instances[0], $principal, GSS_C_ACCEPT]], 'acceptor credentials used wrong inputs');
    check($gss->acceptCalls[0][0] === $rawToken, 'acceptSecContext did not receive the raw token');
    check($gss->acceptCalls[0][1] === KRB5CCache::$instances[1], 'delegated cache argument was misplaced');
    check($gss->acceptCalls[0][2] === GSSAPIChannelBinding::$instances[0], 'channel binding argument was misplaced');
    check(GSSAPIChannelBinding::$instances[0]->applicationData === ['tls-server-end-point:' . $bindingBytes], 'channel binding prefix or bytes changed');

    $reset();
    $directPathConfig = $config;
    $directPathConfig['keytab_ref'] = __FILE__;
    $rejects(
        static fn () => $verifier->verify(base64_encode($rawToken), $directPathConfig, $context),
        'SAND_IAM_KERBEROS_CONFIGURATION_INVALID',
        503,
    );
    check(KRB5CCache::$instances === [], 'unmapped keytab_ref reached the PECL adapter');

    foreach ([
        'mutual flag' => GSS_C_REPLAY_FLAG | 0x800,
        'replay flag' => GSS_C_MUTUAL_FLAG | 0x800,
        'channel-bound flag' => GSS_C_MUTUAL_FLAG | GSS_C_REPLAY_FLAG,
    ] as $case => $flags) {
        $reset();
        GSSAPIContext::$flags = $flags;
        $rejects($verify, 'SAND_IAM_KERBEROS_VERIFICATION_INCOMPLETE', 401);
    }

    $reset();
    GSSAPIContext::$complete = false;
    $rejects($verify, 'SAND_IAM_KERBEROS_VERIFICATION_INCOMPLETE', 401);

    $reset();
    GSSAPIContext::$lifetime = 0;
    $rejects($verify, 'SAND_IAM_KERBEROS_VERIFICATION_INCOMPLETE', 401);

    $reset();
    GSSAPIContext::$credentials['name'] = 'HTTP/foreign.example.test@EXAMPLE.COM';
    $rejects($verify, 'SAND_IAM_KERBEROS_VERIFIER_UNAVAILABLE', 503);

    $reset();
    GSSAPIContext::$credentials['cred_usage'] = 'initiate';
    $rejects($verify, 'SAND_IAM_KERBEROS_VERIFIER_UNAVAILABLE', 503);

    $reset();
    GSSAPIContext::$credentials['mechs'][] = '{ 1 3 6 1 4 1 311 2 2 10 }';
    $rejects($verify, 'SAND_IAM_KERBEROS_VERIFIER_UNAVAILABLE', 503);

    $reset();
    GSSAPIContext::$throwMessage = 'secret KDC detail from /etc/krb5.keytab';
    try {
        $verify();
        throw new \RuntimeException('PECL exception was accepted');
    } catch (ApiException $exception) {
        check($exception->getMessage() === 'SAND_IAM_KERBEROS_AUTHENTICATION_FAILED', 'PECL exception detail leaked');
        check($exception->getCode() === 401, 'PECL exception returned wrong status');
        check(!str_contains($exception->getMessage(), 'secret'), 'PECL secret leaked');
        check(!str_contains($exception->getMessage(), 'keytab'), 'keytab path leaked');
    }

    echo "PECL SPNEGO verifier behavior PASS (strict non-PG doubles; no real KDC/keytab authentication)\n";
}
