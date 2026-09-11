<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

/** IAM-T10 phase-1 source contract floor. This test does not connect to PostgreSQL. */
$package = dirname(__DIR__);
$root = dirname(__DIR__, 3);
$checks = [
    $package . '/app/service/IdentityLifecycleService.php' => [
        "'identity.enable'",
        "'identity.restore'",
        '\'identity.\' . $target',
        '30 * 86400',
        'AuthRefreshToken::whereIn',
        'AuthSession::where',
        'if ($removeRelationships)',
        'MfaFactor::where',
        'WebauthnCredential::where',
        'IdentityBinding::where',
        'IdentityRole::where',
        'IdentityUserType::where',
        'IdentityGroupMember::where',
        'SAND_IAM_IDENTITY_RESTORE_WINDOW_EXPIRED',
    ],
    $package . '/app/service/IdentityGroupService.php' => [
        'SAND_IAM_IDENTITY_GROUP_DEPTH_EXCEEDED',
        'SAND_IAM_IDENTITY_GROUP_CYCLE',
        'SAND_IAM_IDENTITY_GROUP_HAS_CHILDREN',
        'SAND_IAM_IDENTITY_GROUP_HAS_MEMBERS',
        'where(\'application_id\', $applicationId)',
        "'identity_group.member_add'",
        "'identity_group.member_remove'",
    ],
    $package . '/app/admin/controller/IdentityController.php' => [
        "identity_lifecycle_enabled",
        "sand_iam:identity:enable",
        "sand_iam:identity:delete",
        "sand_iam:identity:restore",
        '旧会话、旧令牌和已移除的授权关系不会恢复',
    ],
    $package . '/app/admin/controller/IdentityGroupController.php' => [
        "sand_iam:identity_group:index",
        "sand_iam:identity_group_member:add",
        "'parent_name' =>",
        "'member_count' =>",
        'assertApplication(',
    ],
    $package . '/config/app.php' => ["env('SAND_IAM_IDENTITY_LIFECYCLE_ENABLED', 0)"],
    $package . '/config/route.php' => [
        "'/identity/enable'",
        "'/identity/delete'",
        "'/identity/restore'",
        "'/identity-group/member/add'",
        "'/identity-group/member/remove'",
    ],
];
foreach ($checks as $file => $fragments) {
    $content = file_get_contents($file);
    if (!is_string($content)) { fwrite(STDERR, "unreadable {$file}\n"); exit(1); }
    foreach ($fragments as $fragment) if (!str_contains($content, $fragment)) { fwrite(STDERR, "missing {$fragment} in {$file}\n"); exit(1); }
}

$migrationName = '012_identity_lifecycle_group.pgsql';
$source = $root . '/migrations/' . $migrationName;
$copy = $package . '/migrations/' . $migrationName;
if (!is_file($source) || !is_file($copy) || hash_file('sha256', $source) !== hash_file('sha256', $copy)) { fwrite(STDERR, "root/plugin migration differs: {$migrationName}\n"); exit(1); }
$sql = file_get_contents($source);
foreach (['lifecycle_state', 'external_guest_ref_hash', 'sand_iam_identity_group', 'sand_iam_identity_group_member', 'fk_sand_iam_group_member_identity_app', 'CHECK (depth BETWEEN 1 AND 8)'] as $fragment) {
    if (!is_string($sql) || !str_contains($sql, $fragment)) { fwrite(STDERR, "migration missing {$fragment}\n"); exit(1); }
}
if (preg_match('/\bAUTO_INCREMENT\b|\bUNSIGNED\b|\bENGINE\s*=/i', (string) $sql)) { fwrite(STDERR, "migration contains non-PostgreSQL syntax\n"); exit(1); }

echo "identity lifecycle and group non-PG contract checks passed\n";
