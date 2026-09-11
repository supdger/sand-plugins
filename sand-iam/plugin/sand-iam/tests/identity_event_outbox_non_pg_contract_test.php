<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

/** IAM-T10/T12 transactional identity event source contract. No database is used. */
$package = dirname(__DIR__);

$checks = [
    $package . '/app/service/IdentityEventPublisher.php' => [
        "'identity.created' => 'create'",
        "'identity.disabled' => 'disable'",
        "'identity.deleted' => 'delete'",
        "identity_event_outbox_enabled', 0",
        'caller\'s transaction',
        "'webhook.delivery_enqueue'",
        "'identity_event'",
        "'event_id' => \$eventId",
        'sand_iam_acceptance_',
        "WebhookEndpoint::where('application_id', \$applicationId)",
        "SyncConnector::where('application_id', \$applicationId)",
        "whereIn('direction', ['outbound', 'bidirectional'])",
        "whereNotNull('encrypted_config')",
        "'encrypted_payload' => \$this->syncCipher->encryptArray",
        "'changed_fields' => \$changedFields",
    ],
    $package . '/app/service/HumanAuthService.php' => [
        "publish(\$application, \$identity, 'identity.created'",
        "publish(\$application, \$identity, 'identity.updated'",
    ],
    $package . '/app/service/IdentityLifecycleService.php' => [
        "'identity.enabled'",
        "'identity.restored'",
        "'identity.' . \$target",
    ],
    $package . '/app/service/GuestIdentityService.php' => [
        "\$created ? 'identity.created' : 'identity.updated'",
    ],
    $package . '/app/service/SelfServiceService.php' => [
        "'identity.updated', ['display_name']",
    ],
    $package . '/app/service/IdentityImportService.php' => [
        "'identity.updated', ['display_name', 'groups']",
    ],
    $package . '/app/service/IdentityGroupService.php' => [
        "'identity.updated', ['groups']",
    ],
    $package . '/app/service/SyncConnectorService.php' => [
        "\$requestId, false, false",
    ],
    $package . '/config/app.php' => [
        "SAND_IAM_IDENTITY_EVENT_OUTBOX_ENABLED', 0",
    ],
];

foreach ($checks as $file => $fragments) {
    $content = file_get_contents($file);
    if (!is_string($content)) {
        fwrite(STDERR, "unreadable {$file}\n");
        exit(1);
    }
    foreach ($fragments as $fragment) {
        if (!str_contains($content, $fragment)) {
            fwrite(STDERR, "missing {$fragment} in {$file}\n");
            exit(1);
        }
    }
}

$publisher = (string) file_get_contents($package . '/app/service/IdentityEventPublisher.php');
foreach (['password', 'email', 'phone', 'token', 'subject'] as $forbidden) {
    if (preg_match("/['\"]{$forbidden}['\"]\s*=>/", $publisher)) {
        fwrite(STDERR, "identity event payload exposes {$forbidden}\n");
        exit(1);
    }
}

echo "transactional identity event outbox non-PG contract checks passed\n";
