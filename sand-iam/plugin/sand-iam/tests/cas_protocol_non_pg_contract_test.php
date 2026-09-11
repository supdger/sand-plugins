<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

/** IAM-T11 CAS source contract. No database is used. */
$package = dirname(__DIR__);
$root = dirname(__DIR__, 3);

$checks = [
    $package . '/app/service/CasProtocolService.php' => [
        "'CRT-' . \$this->randomToken(36)",
        "'ST-' . \$this->randomToken(40)",
        "'cas-request:' . \$token",
        "'cas-ticket:' . \$ticket",
        "time() + 300",
        "->where('application_id', (int) \$request->application_id)",
        "->where('application_id', (int) \$record->application_id)",
        "'organization_code' => (string) \$organization->code",
        "'application_code' => (string) \$application->code",
        "lock(true)->find()",
        "'consumed_time' => date('Y-m-d H:i:s')",
        "hash_equals((string) \$service->service_url, \$serviceUrl)",
        "public function reject(string \$requestToken, string \$accessToken, string \$requestId): array",
        "'cas.login.reject'",
        "'reason' => 'user_denied'",
        "<cas:authenticationSuccess>",
        "<cas:authenticationFailure code=\"INVALID_TICKET\">",
        'ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE',
        'SAND_IAM_CAS_OPTION_UNSUPPORTED',
    ],
    $package . '/app/api/controller/CasController.php' => [
        'function login(Request $request)',
        'function validate(Request $request)',
        'function reject(Request $request)',
        'function serviceValidate(Request $request)',
        'function p3ServiceValidate(Request $request)',
        "'Cache-Control' => 'no-store'",
        "'Bearer ', 7",
    ],
    $package . '/app/admin/controller/CasServiceController.php' => [
        'CAS 接入服务',
        "['display_name', 'email']",
        "CasService::where('service_url', \$url)",
        '精确 HTTPS 地址',
    ],
    $package . '/config/route.php' => [
        '/cas/login',
        '/cas/interaction/confirm',
        '/cas/interaction/reject',
        '/cas/validate',
        '/cas/serviceValidate',
        '/cas/p3/serviceValidate',
        'CasController::class',
    ],
    $package . '/config/app.php' => ["SAND_IAM_CAS_ENABLED', 0"],
];

foreach ($checks as $file => $fragments) {
    $content = file_get_contents($file);
    if (!is_string($content)) { fwrite(STDERR, "unreadable {$file}\n"); exit(1); }
    foreach ($fragments as $fragment) {
        if (!str_contains($content, $fragment)) { fwrite(STDERR, "missing {$fragment} in {$file}\n"); exit(1); }
    }
}

$name = '017_cas_protocol.pgsql';
$source = $root . '/migrations/' . $name;
$copy = $package . '/migrations/' . $name;
if (!is_file($source) || !is_file($copy) || hash_file('sha256', $source) !== hash_file('sha256', $copy)) {
    fwrite(STDERR, "017 root/plugin copies differ\n");
    exit(1);
}
$sql = (string) file_get_contents($source);
foreach (['sand_iam_cas_service', 'sand_iam_cas_login_request', 'sand_iam_cas_ticket', 'request_hash', 'ticket_hash', 'UNIQUE (service_url)', 'jsonb'] as $fragment) {
    if (!str_contains($sql, $fragment)) { fwrite(STDERR, "017 missing {$fragment}\n"); exit(1); }
}
foreach (['ENGINE=', 'AUTO_INCREMENT', '`'] as $mysqlFragment) {
    if (str_contains($sql, $mysqlFragment)) { fwrite(STDERR, "MySQL syntax {$mysqlFragment} found in 017\n"); exit(1); }
}

echo "CAS protocol non-PG contract checks passed\n";
