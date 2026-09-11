<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

/** IAM-T11 Kerberos/SPNEGO source contract. No database or realm is used. */
$package = dirname(__DIR__);
$checks = [
    $package . '/app/kerberos/SpnegoVerifier.php' => [
        'service_principal:string',
        'mutual_auth:bool',
        'channel_binding:bool',
        'replay_protected:bool',
    ],
    $package . '/app/kerberos/UnavailableSpnegoVerifier.php' => ['SAND_IAM_KERBEROS_VERIFIER_UNAVAILABLE'],
    $package . '/app/kerberos/SpnegoContextResolver.php' => ['trusted TLS terminator/server API', 'ignore client-supplied identity and binding headers'],
    $package . '/app/kerberos/UnavailableSpnegoContextResolver.php' => ['SAND_IAM_KERBEROS_TRANSPORT_CONTEXT_UNAVAILABLE'],
    $package . '/app/service/KerberosProtocolService.php' => [
        "strncasecmp(\$authorization, 'Negotiate ', 10)",
        'base64_decode($token, true)',
        "'channel_binding' => \$channelBinding",
        "(\$result['mutual_auth'] ?? false) !== true",
        "(\$result['channel_binding'] ?? false) !== true",
        "(\$result['replay_protected'] ?? false) !== true",
        'SAND_IAM_KERBEROS_SERVICE_PRINCIPAL_MISMATCH',
        "where('subject', \$principal)",
        'SAND_IAM_KERBEROS_ACCOUNT_NOT_LINKED',
        'issueFederatedSession',
    ],
    $package . '/app/service/FederationService.php' => [
        "'kerberos'",
        "'service_principal'",
        "'keytab_ref'",
        "'allowed_realms'",
        "'require_channel_binding'",
        "'require_replay_cache'",
        "'require_mutual_auth'",
    ],
    $package . '/app/api/controller/KerberosController.php' => [
        "'WWW-Authenticate' => 'Negotiate'",
        'contextResolver()->resolve($request)',
        '企业网络认证未通过',
    ],
    $package . '/config/app.php' => ["SAND_IAM_KERBEROS_ENABLED', 0", 'SAND_IAM_KERBEROS_VERIFIER', 'SAND_IAM_KERBEROS_CONTEXT_RESOLVER'],
    $package . '/config/route.php' => ['/federation/kerberos/negotiate', 'KerberosController::class'],
];

foreach ($checks as $file => $fragments) {
    $content = file_get_contents($file);
    if (!is_string($content)) { fwrite(STDERR, "unreadable {$file}\n"); exit(1); }
    foreach ($fragments as $fragment) if (!str_contains($content, $fragment)) { fwrite(STDERR, "missing {$fragment} in {$file}\n"); exit(1); }
}

echo "Kerberos/SPNEGO non-PG contract checks passed\n";
