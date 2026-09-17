<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/federation/LdapDirectoryAdapter.php';
require_once dirname(__DIR__) . '/app/federation/NativeLdapDirectoryAdapter.php';

use plugin\SandIam\app\federation\NativeLdapDirectoryAdapter;

$reflection = new ReflectionClass(NativeLdapDirectoryAdapter::class);
$constant = $reflection->getReflectionConstant('LDAPS_URI_PATTERN');
$pattern = $constant?->getValue();
if (!is_string($pattern)) {
    throw new RuntimeException('LDAPS URI pattern is unavailable');
}

$accepted = [
    'ldaps://directory.example.test',
    'ldaps://directory.example.test:636',
    'ldaps://127.0.0.1:1636',
];
foreach ($accepted as $uri) {
    if (preg_match($pattern, $uri, $matches) !== 1) {
        throw new RuntimeException("valid LDAPS URI was rejected: {$uri}");
    }
}

$rejected = [
    'ldap://directory.example.test:389',
    'ldaps://directory.example.test/path',
    'ldaps://user@directory.example.test',
    'ldaps://directory.example.test?query',
    'ldaps://directory.example.test#fragment',
    'ldaps://directory.example.test:0',
    'ldaps://directory.example.test:65536',
];
foreach ($rejected as $uri) {
    $matched = preg_match($pattern, $uri, $matches) === 1;
    $port = isset($matches[2]) ? (int) $matches[2] : null;
    if ($matched && ($port === null || ($port >= 1 && $port <= 65535))) {
        throw new RuntimeException("invalid LDAPS URI was accepted: {$uri}");
    }
}

echo "SandIAM native LDAP URI validation non-PG checks passed\n";
