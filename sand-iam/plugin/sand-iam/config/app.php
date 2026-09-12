<?php

return [
    // Never expose plugin debug behavior by default in a distributable build.
    'debug' => (int) env('SAND_IAM_DEBUG', 0) === 1,
    'controller_suffix' => 'Controller',
    'controller_reuse' => false,
    'version' => '0.7.1',
    // Deployment-managed secret. Empty means runtime context issuance fails closed.
    'context_signing_key' => env('SAND_IAM_CONTEXT_SIGNING_KEY', ''),
    // Required for all human-authentication secrets. Empty means auth fails closed.
    'auth_pepper' => env('SAND_IAM_AUTH_PEPPER', ''),
    // This version is immutable for existing records in T01. A pepper rotation
    // requires a separately approved migration that retains prior verifiers.
    'auth_pepper_version' => env('SAND_IAM_AUTH_PEPPER_VERSION', 'v1'),
    // A configured class must expose public static send(string $destination, string $code, array $context): void.
    // The plugin never fakes delivery or returns verification codes from production APIs.
    'auth_email_sender' => env('SAND_IAM_AUTH_EMAIL_SENDER', ''),
    'auth_phone_sender' => env('SAND_IAM_AUTH_PHONE_SENDER', ''),
    // Organization-owned Email/SMS/Captcha/Notification provider settings are
    // encrypted in PostgreSQL. Driver classes remain deployment-controlled so
    // database writers cannot select arbitrary PHP classes.
    'message_encryption_key' => env('SAND_IAM_MESSAGE_ENCRYPTION_KEY', ''),
    'message_encryption_key_version' => env('SAND_IAM_MESSAGE_ENCRYPTION_KEY_VERSION', 'v1'),
    'message_encryption_keys' => env('SAND_IAM_MESSAGE_ENCRYPTION_KEYS', ''),
    // Feature switches remain deployment-owned even though their migrations
    // are part of the verified package lifecycle.
    'application_experience_enabled' => (int) env('SAND_IAM_APPLICATION_EXPERIENCE_ENABLED', 0),
    'message_provider_enabled' => (int) env('SAND_IAM_MESSAGE_PROVIDER_ENABLED', 0),
    'identity_lifecycle_enabled' => (int) env('SAND_IAM_IDENTITY_LIFECYCLE_ENABLED', 0),
    // Destructive acceptance-fixture cleanup remains off unless an operator
    // explicitly enables it for a controlled host and fixed fixture prefix.
    'acceptance_fixture_cleanup_enabled' => (int) env('SAND_IAM_ACCEPTANCE_FIXTURE_CLEANUP_ENABLED', 0),
    'invitation_encryption_key' => env('SAND_IAM_INVITATION_ENCRYPTION_KEY', ''),
    'invitation_encryption_key_version' => env('SAND_IAM_INVITATION_ENCRYPTION_KEY_VERSION', 'v1'),
    'invitation_encryption_keys' => env('SAND_IAM_INVITATION_ENCRYPTION_KEYS', ''),
    'invitation_token_pepper' => env('SAND_IAM_INVITATION_TOKEN_PEPPER', ''),
    'invitation_accept_url' => env('SAND_IAM_INVITATION_ACCEPT_URL', ''),
    'guest_reference_pepper' => env('SAND_IAM_GUEST_REFERENCE_PEPPER', ''),
    'import_encryption_key' => env('SAND_IAM_IMPORT_ENCRYPTION_KEY', ''),
    'import_encryption_key_version' => env('SAND_IAM_IMPORT_ENCRYPTION_KEY_VERSION', 'v1'),
    'import_encryption_keys' => env('SAND_IAM_IMPORT_ENCRYPTION_KEYS', ''),
    'sync_encryption_key' => env('SAND_IAM_SYNC_ENCRYPTION_KEY', ''),
    'sync_encryption_key_version' => env('SAND_IAM_SYNC_ENCRYPTION_KEY_VERSION', 'v1'),
    'sync_encryption_keys' => env('SAND_IAM_SYNC_ENCRYPTION_KEYS', ''),
    'sync_drivers' => env('SAND_IAM_SYNC_DRIVERS', ''),
    'sync_reference_pepper' => env('SAND_IAM_SYNC_REFERENCE_PEPPER', ''),
    // Rejected or failed outbound events become operator-retryable terminal
    // records instead of remaining pending forever.
    'sync_outbox_max_attempts' => max(1, min(100, (int) env('SAND_IAM_SYNC_OUTBOX_MAX_ATTEMPTS', 10))),
    // The directory-sync process itself is separately opt-in in process.php.
    // These bounds apply only after both lifecycle and worker switches are on.
    'directory_sync_worker_interval_seconds' => max(1, (int) env('SAND_IAM_DIRECTORY_SYNC_WORKER_INTERVAL_SECONDS', 60)),
    'directory_sync_worker_batch_size' => max(1, min(100, (int) env('SAND_IAM_DIRECTORY_SYNC_WORKER_BATCH_SIZE', 20))),
    'directory_sync_worker_retry_base_seconds' => max(1, (int) env('SAND_IAM_DIRECTORY_SYNC_WORKER_RETRY_BASE_SECONDS', 5)),
    'directory_sync_worker_retry_max_seconds' => max(1, (int) env('SAND_IAM_DIRECTORY_SYNC_WORKER_RETRY_MAX_SECONDS', 300)),
    'identity_event_outbox_enabled' => (int) env('SAND_IAM_IDENTITY_EVENT_OUTBOX_ENABLED', 0),
    'audit_event_outbox_enabled' => (int) env('SAND_IAM_AUDIT_EVENT_OUTBOX_ENABLED', 0),
    'application_network_policy_enabled' => (int) env('SAND_IAM_APPLICATION_NETWORK_POLICY_ENABLED', 0),
    'security_alert_enabled' => (int) env('SAND_IAM_SECURITY_ALERT_ENABLED', 0),
    'security_alert_fingerprint_key' => (string) env('SAND_IAM_SECURITY_ALERT_FINGERPRINT_KEY', ''),
    'audit_purge_enabled' => (int) env('SAND_IAM_AUDIT_PURGE_ENABLED', 0),
    'audit_archive_interval_seconds' => (int) env('SAND_IAM_AUDIT_ARCHIVE_INTERVAL_SECONDS', 3600),
    'audit_archive_batch_size' => (int) env('SAND_IAM_AUDIT_ARCHIVE_BATCH_SIZE', 200),
    // Idempotency records are operational state, not audit evidence. Retain
    // them for at least 30 days and prune only succeeded rows in bounded batches.
    'security_operation_retention_days' => max(30, min(3650, (int) env('SAND_IAM_SECURITY_OPERATION_RETENTION_DAYS', 30))),
    'security_operation_retention_interval_seconds' => max(300, (int) env('SAND_IAM_SECURITY_OPERATION_RETENTION_INTERVAL_SECONDS', 3600)),
    'security_operation_retention_batch_size' => max(1, min(1000, (int) env('SAND_IAM_SECURITY_OPERATION_RETENTION_BATCH_SIZE', 200))),
    // Authentication throttling windows last 60 seconds. Keep expired rows for
    // at least one hour so cleanup cannot race an active or slightly skewed host.
    'auth_rate_limit_retention_hours' => max(1, min(168, (int) env('SAND_IAM_AUTH_RATE_LIMIT_RETENTION_HOURS', 24))),
    'oauth_dynamic_registration_enabled' => (int) env('SAND_IAM_OAUTH_DYNAMIC_REGISTRATION_ENABLED', 0),
    'oidc_frontchannel_logout_enabled' => (int) env('SAND_IAM_OIDC_FRONTCHANNEL_LOGOUT_ENABLED', 0),
    'oidc_backchannel_logout_enabled' => (int) env('SAND_IAM_OIDC_BACKCHANNEL_LOGOUT_ENABLED', 0),
    'oidc_logout_encryption_key' => env('SAND_IAM_OIDC_LOGOUT_ENCRYPTION_KEY', ''),
    'oidc_logout_encryption_key_version' => env('SAND_IAM_OIDC_LOGOUT_ENCRYPTION_KEY_VERSION', 'v1'),
    'oidc_logout_encryption_keys' => env('SAND_IAM_OIDC_LOGOUT_ENCRYPTION_KEYS', ''),
    'oidc_logout_poll_interval_ms' => (int) env('SAND_IAM_OIDC_LOGOUT_POLL_INTERVAL_MS', 1000),
    'oidc_logout_batch_size' => (int) env('SAND_IAM_OIDC_LOGOUT_BATCH_SIZE', 20),
    // CAS uses independent application-user sessions and exact registered
    // service URLs. Keep disabled until migration 017 passes PostgreSQL gates.
    'cas_enabled' => (int) env('SAND_IAM_CAS_ENABLED', 0),
    // A deployment-controlled GSSAPI implementation must satisfy the
    // SpnegoVerifier contract. Missing verifier/keytab/replay support fails closed.
    'kerberos_enabled' => (int) env('SAND_IAM_KERBEROS_ENABLED', 0),
    'kerberos_verifier' => env('SAND_IAM_KERBEROS_VERIFIER', ''),
    'kerberos_context_resolver' => env('SAND_IAM_KERBEROS_CONTEXT_RESOLVER', ''),
    // RADIUS Server is a dedicated UDP worker. Secrets are per NAS and
    // encrypted independently from every other SandIAM key family.
    'radius_server_enabled' => (int) env('SAND_IAM_RADIUS_SERVER_ENABLED', 0),
    'radius_bind_host' => env('SAND_IAM_RADIUS_BIND_HOST', '127.0.0.1'),
    'radius_auth_port' => (int) env('SAND_IAM_RADIUS_AUTH_PORT', 1812),
    'radius_accounting_port' => (int) env('SAND_IAM_RADIUS_ACCOUNTING_PORT', 1813),
    'radius_encryption_key' => env('SAND_IAM_RADIUS_ENCRYPTION_KEY', ''),
    'radius_encryption_key_version' => env('SAND_IAM_RADIUS_ENCRYPTION_KEY_VERSION', 'v1'),
    'radius_encryption_keys' => env('SAND_IAM_RADIUS_ENCRYPTION_KEYS', ''),
    'radius_replay_key' => env('SAND_IAM_RADIUS_REPLAY_KEY', ''),
    // JSON object: {"aliyun-sms":"App\\Iam\\AliyunSmsDriver"}.
    'message_drivers' => env('SAND_IAM_MESSAGE_DRIVERS', ''),
    // Base64-encoded 32-byte key. MFA secrets and ephemeral WebAuthn challenges
    // fail closed when it is absent or invalid; do not reuse auth_pepper here.
    'mfa_encryption_key' => env('SAND_IAM_MFA_ENCRYPTION_KEY', ''),
    'mfa_encryption_key_version' => env('SAND_IAM_MFA_ENCRYPTION_KEY_VERSION', 'v1'),
    // JSON object of retained version => base64 key entries used only to decrypt
    // records during key rotation, for example {"v1":"..."}. New writes always
    // use mfa_encryption_key_version + mfa_encryption_key.
    'mfa_encryption_keys' => env('SAND_IAM_MFA_ENCRYPTION_KEYS', ''),
    // Legacy deployment key used only before the first managed rotation. Once
    // a signing key is rotated, the active private PEM is stored only in the
    // existing versioned OIDC logout envelope and never returned by APIs.
    'oidc_issuer' => env('SAND_IAM_OIDC_ISSUER', ''),
    'oidc_private_key_base64' => env('SAND_IAM_OIDC_PRIVATE_KEY_BASE64', ''),
    'oidc_kid' => env('SAND_IAM_OIDC_KID', ''),
    // A retired signing key remains in JWKS for max signed-token TTL (900s)
    // plus this bounded clock-skew allowance. It cannot be manually retired
    // before that grace window ends.
    'oidc_signing_key_clock_skew_seconds' => (int) env('SAND_IAM_OIDC_SIGNING_KEY_CLOCK_SKEW_SECONDS', 300),
    // Stable deployment secret used only for pairwise OIDC subject derivation.
    // Rotating it changes all subjects, so operational rotation requires a
    // separately versioned migration and dual-subject compatibility window.
    'oidc_subject_key' => env('SAND_IAM_OIDC_SUBJECT_KEY', ''),
    // Dedicated 32-byte base64 key for external provider configuration and
    // OIDC federation transaction PKCE material. Never reuse MFA keys.
    'federation_encryption_key' => env('SAND_IAM_FEDERATION_ENCRYPTION_KEY', ''),
    // Dedicated webhook signing-secret encryption key. Ciphertexts retain the
    // key version so deployments can rotate without losing pending deliveries.
    'webhook_encryption_key' => env('SAND_IAM_WEBHOOK_ENCRYPTION_KEY', ''),
    'webhook_encryption_key_version' => env('SAND_IAM_WEBHOOK_ENCRYPTION_KEY_VERSION', 'v1'),
    'webhook_encryption_keys' => env('SAND_IAM_WEBHOOK_ENCRYPTION_KEYS', ''),
    'webhook_poll_interval_ms' => max(250, (int) env('SAND_IAM_WEBHOOK_POLL_INTERVAL_MS', 1000)),
    'webhook_batch_size' => max(1, min(100, (int) env('SAND_IAM_WEBHOOK_BATCH_SIZE', 20))),
];
