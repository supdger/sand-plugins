<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

/** IAM-T10 invitation source contract. No database or network is used. */
$package = dirname(__DIR__); $root = dirname(__DIR__, 3);
$checks = [
    $package . '/app/service/IdentityInvitationService.php' => [
        "'siam_inv_'",
        "hash_hmac('sha256'",
        '\'target:\' . $target',
        '\'token:\' . $token',
        "['pending', 'delivery_failed']",
        "'state' => 'sending'",
        "'state' => 'delivery_failed'",
        "'state' => 'accepted'",
        "'state' => 'expired'",
        'encrypted_delivery_token',
        'sendMessage((int) $application->id',
        "'invitation'",
        "activateInvitation(",
        'lock(true)',
    ],
    $package . '/app/service/InvitationSecretCipher.php' => ['sodium_crypto_secretbox(', 'sodium_crypto_secretbox_open(', 'invitation_encryption_key_version', 'invitation_encryption_keys'],
    $package . '/app/admin/controller/IdentityInvitationController.php' => ["'target_masked' =>", "'initial_group_names' =>", 'if ($detail)', "sand_iam:identity_invitation:send", '目标地址和邀请令牌不会在后台回显'],
    $package . '/app/middleware/InvitationSensitiveMiddleware.php' => ['invitation targets, tokens and chosen passwords', "'application_user'", '\'error_code\' => $code', "withHeader('Cache-Control', 'no-store')"],
    $package . '/app/api/controller/InvitationController.php' => ['IdentityInvitationService', "withHeader('Cache-Control', 'no-store')"],
    $package . '/config/route.php' => ['identity-invitation/send', '/api/sand-iam/v1/invitations/accept', 'InvitationSensitiveMiddleware::class'],
];
foreach ($checks as $file => $fragments) { $content = file_get_contents($file); if (!is_string($content)) { fwrite(STDERR, "unreadable {$file}\n"); exit(1); } foreach ($fragments as $fragment) if (!str_contains($content, $fragment)) { fwrite(STDERR, "missing {$fragment} in {$file}\n"); exit(1); } }
$controller = file_get_contents($package . '/app/admin/controller/IdentityInvitationController.php');
foreach (['encrypted_target', 'token_hash', 'encrypted_delivery_token'] as $secretField) if (is_string($controller) && preg_match('/return\s*\[[^;]*[\'\"]' . preg_quote($secretField, '/') . '[\'\"]\s*=>/s', $controller)) { fwrite(STDERR, "invitation DTO exposes {$secretField}\n"); exit(1); }
$name = '013_identity_invitation.pgsql'; $source = $root . '/migrations/' . $name; $copy = $package . '/migrations/' . $name;
if (!is_file($source) || !is_file($copy) || hash_file('sha256', $source) !== hash_file('sha256', $copy)) { fwrite(STDERR, "root/plugin migration differs: {$name}\n"); exit(1); }
$sql = file_get_contents($source); foreach (['sand_iam_identity_invitation', 'uk_sand_iam_invitation_active_target', 'uk_sand_iam_invitation_active_guest', 'encrypted_delivery_token', 'fk_sand_iam_invitation_identity_app', 'guest_identity_id'] as $fragment) if (!is_string($sql) || !str_contains($sql, $fragment)) { fwrite(STDERR, "migration missing {$fragment}\n"); exit(1); }
if (preg_match('/\bAUTO_INCREMENT\b|\bUNSIGNED\b|\bENGINE\s*=/i', (string) $sql)) { fwrite(STDERR, "migration contains non-PostgreSQL syntax\n"); exit(1); }
echo "identity invitation non-PG contract checks passed\n";
