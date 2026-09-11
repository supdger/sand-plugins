<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

/** IAM-T10 guest identity/upgrade source contract. No database is used. */
$package = dirname(__DIR__); $root = dirname(__DIR__, 3);
$checks = [
    $package . '/app/api/controller/GuestIdentityController.php' => ['IdentityContextProvider())->verify(', "'sand-iam'", "'identity.guest.upsert'", "['application_id']", "'external_guest_id'"],
    $package . '/app/service/GuestIdentityService.php' => ['external_guest_ref_hash', "'lifecycle_state' => 'guest'", 'hash_hmac(', "'context'", "'identity.guest_upsert'", 'lock(true)', '$retry === 0', "'23505'"],
    $package . '/app/service/HumanAuthService.php' => ['upgradeGuestInvitation(', 'SAND_IAM_GUEST_NOT_UPGRADEABLE', "'identity.guest_upgrade'", "'lifecycle_state' => 'active'"],
    $package . '/app/service/IdentityInvitationService.php' => ['guest_identity_id', 'upgradeGuestInvitation(', "where('lifecycle_state', 'guest')"],
    $package . '/app/middleware/GuestIdentitySensitiveMiddleware.php' => ['external guest references and identity contexts', "'context', 'redacted'", '\'error_code\' => $code'],
    $package . '/config/route.php' => ['/api/sand-iam/v1/guests/upsert', 'GuestIdentitySensitiveMiddleware::class'],
    $package . '/config/app.php' => ["env('SAND_IAM_GUEST_REFERENCE_PEPPER', '')"],
];
foreach ($checks as $file => $fragments) { $content = file_get_contents($file); if (!is_string($content)) { fwrite(STDERR, "unreadable {$file}\n"); exit(1); } foreach ($fragments as $fragment) if (!str_contains($content, $fragment)) { fwrite(STDERR, "missing {$fragment} in {$file}\n"); exit(1); } }
$migration = file_get_contents($root . '/migrations/012_identity_lifecycle_group.pgsql');
foreach (['external_guest_ref_hash', "'identity.guest.upsert'", "'sand-iam'", 'ON CONFLICT (service_id, code)'] as $fragment) if (!is_string($migration) || !str_contains($migration, $fragment)) { fwrite(STDERR, "012 missing guest contract {$fragment}\n"); exit(1); }
if (hash_file('sha256', $root . '/migrations/012_identity_lifecycle_group.pgsql') !== hash_file('sha256', $package . '/migrations/012_identity_lifecycle_group.pgsql')) { fwrite(STDERR, "012 root/plugin copies differ\n"); exit(1); }
echo "guest identity and upgrade non-PG contract checks passed\n";
