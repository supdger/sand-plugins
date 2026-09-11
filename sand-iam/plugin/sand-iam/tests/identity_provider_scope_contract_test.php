<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

/**
 * Static regression checks for the schema and legacy admin payload contract.
 * Execute with: php plugin/sand-iam/tests/identity_provider_scope_contract_test.php
 */

$sandIamRoot = dirname(__DIR__, 3);
$files = [
    $sandIamRoot . '/install.sql' => [
        'UNIQUE (identity_provider_id, subject)',
        'provider_scope_application_key',
        'FOREIGN KEY (identity_id, application_id)',
        'sand_iam_identity_provider_application',
    ],
    $sandIamRoot . '/migrations/002_identity_provider_scope.pgsql' => [
        'binding.provider_code',
        'SET application_id = identity_record.application_id',
        'UNIQUE (identity_provider_id, subject)',
    ],
    dirname(__DIR__) . '/app/admin/controller/IdentityBindingController.php' => [
        "post('provider_code'",
        "'application_id' => \$identity->application_id",
        "\$payload['provider_code'] = (string) \$provider->code",
        'IdentityProviderApplication::where',
        'mountedProvider',
    ],
    dirname(__DIR__) . '/app/admin/controller/IdentityProviderController.php' => [
        "in_array(\$scope, ['application', 'organization'], true)",
        "'scope_type' => \$scope",
        "'application_id' => \$scope === 'application'",
        'IdentityProviderApplication::create',
    ],
];

foreach ($files as $file => $requiredFragments) {
    $content = file_get_contents($file);
    if ($content === false) {
        fwrite(STDERR, "Unable to read {$file}\n");
        exit(1);
    }
    foreach ($requiredFragments as $fragment) {
        if (!str_contains($content, $fragment)) {
            fwrite(STDERR, "Missing {$fragment} in {$file}\n");
            exit(1);
        }
    }
}

$bindingController = file_get_contents(dirname(__DIR__) . '/app/admin/controller/IdentityBindingController.php');
if ($bindingController === false || str_contains($bindingController, "IdentityProvider::create([")) {
    fwrite(STDERR, "legacy provider_code fallback must not create an incomplete provider\n");
    exit(1);
}

$install = file_get_contents($sandIamRoot . '/install.sql');
if ($install === false || str_contains($install, 'UNIQUE (provider_code, subject)')) {
    fwrite(STDERR, "identity binding must not restore provider_code + subject as a global unique key\n");
    exit(1);
}

$mirroredLifecycleFiles = ['update.sql'];
foreach ($mirroredLifecycleFiles as $filename) {
    $packageFile = $sandIamRoot . '/' . $filename;
    $pluginFile = dirname(__DIR__) . '/' . $filename;
    if (hash_file('sha256', $packageFile) !== hash_file('sha256', $pluginFile)) {
        $packageLines = file($packageFile, FILE_IGNORE_NEW_LINES) ?: [];
        $pluginLines = file($pluginFile, FILE_IGNORE_NEW_LINES) ?: [];
        $lineCount = max(count($packageLines), count($pluginLines));
        $firstDifference = 0;
        for ($index = 0; $index < $lineCount; $index++) {
            if (($packageLines[$index] ?? null) !== ($pluginLines[$index] ?? null)) {
                $firstDifference = $index + 1;
                break;
            }
        }
        fwrite(STDERR, "Lifecycle SQL mismatch: {$filename}, first difference at line {$firstDifference}\n");
        exit(1);
    }
}

echo "identity provider scope contract checks passed\n";
