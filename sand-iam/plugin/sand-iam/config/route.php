<?php

use plugin\SandIam\app\admin\controller\ApplicationController;
use plugin\SandIam\app\admin\controller\AdminOrganizationGrantController;
use plugin\SandIam\app\admin\controller\AdminApplicationGrantController;
use plugin\SandIam\app\admin\controller\AcceptanceFixtureController;
use plugin\SandIam\app\admin\controller\AuditLogController;
use plugin\SandIam\app\admin\controller\AuthPolicyController;
use plugin\SandIam\app\admin\controller\ApplicationExperienceController;
use plugin\SandIam\app\admin\controller\MessageProviderController;
use plugin\SandIam\app\admin\controller\IdentityGroupController;
use plugin\SandIam\app\admin\controller\IdentityGroupRoleController;
use plugin\SandIam\app\admin\controller\IdentityInvitationController;
use plugin\SandIam\app\admin\controller\IdentityImportController;
use plugin\SandIam\app\admin\controller\SyncConnectorController;
use plugin\SandIam\app\admin\controller\OAuthClientController;
use plugin\SandIam\app\admin\controller\OidcSigningKeyController;
use plugin\SandIam\app\admin\controller\OAuthRegistrationTokenController;
use plugin\SandIam\app\admin\controller\CasServiceController;
use plugin\SandIam\app\admin\controller\RadiusNasController;
use plugin\SandIam\app\admin\controller\DeveloperController;
use plugin\SandIam\app\admin\controller\ApplicationNetworkPolicyController;
use plugin\SandIam\app\admin\controller\AuditRetentionPolicyController;
use plugin\SandIam\app\admin\controller\SecurityAlertController;
use plugin\SandIam\app\admin\controller\InitializationController;
use plugin\SandIam\app\admin\controller\CredentialController;
use plugin\SandIam\app\admin\controller\EnvironmentController;
use plugin\SandIam\app\admin\controller\IdentityController;
use plugin\SandIam\app\admin\controller\IdentityBindingController;
use plugin\SandIam\app\admin\controller\IdentityProviderController;
use plugin\SandIam\app\admin\controller\IdentityProviderPresetController;
use plugin\SandIam\app\admin\controller\IdentityRoleController;
use plugin\SandIam\app\admin\controller\IdentityUserTypeController;
use plugin\SandIam\app\admin\controller\OrganizationController;
use plugin\SandIam\app\admin\controller\PolicyController;
use plugin\SandIam\app\admin\controller\ResourceController;
use plugin\SandIam\app\admin\controller\RoleController;
use plugin\SandIam\app\admin\controller\ServiceActionController;
use plugin\SandIam\app\admin\controller\ServiceController;
use plugin\SandIam\app\admin\controller\ServiceGrantController;
use plugin\SandIam\app\admin\controller\UserTypeController;
use plugin\SandIam\app\admin\controller\WorkloadClientController;
use plugin\SandIam\app\admin\controller\FederationAdminController;
use plugin\SandIam\app\admin\controller\ApiResourceController;
use plugin\SandIam\app\admin\controller\ApplicationBusinessActionController;
use plugin\SandIam\app\admin\controller\ApiRouteBindingController;
use plugin\SandIam\app\admin\controller\WebhookController;
use plugin\SandIam\app\api\controller\AuthorizationController;
use plugin\SandIam\app\api\controller\SelfServiceController;
use plugin\SandIam\app\api\controller\RuntimeContextController;
use plugin\SandIam\app\api\controller\AuthController;
use plugin\SandIam\app\api\controller\OAuthOidcController;
use plugin\SandIam\app\api\controller\FederationController;
use plugin\SandIam\app\api\controller\ScimController;
use plugin\SandIam\app\api\controller\ExperienceController;
use plugin\SandIam\app\api\controller\InvitationController;
use plugin\SandIam\app\api\controller\GuestIdentityController;
use plugin\SandIam\app\api\controller\CasController;
use plugin\SandIam\app\api\controller\KerberosController;
use plugin\SandIam\app\api\controller\AccountPortalController;
use plugin\SandIam\app\middleware\FederationSensitiveConfigMiddleware;
use plugin\SandIam\app\middleware\FederationProtocolMiddleware;
use plugin\SandIam\app\middleware\ScimProtocolMiddleware;
use plugin\SandIam\app\middleware\MessageProviderSensitiveMiddleware;
use plugin\SandIam\app\middleware\InvitationSensitiveMiddleware;
use plugin\SandIam\app\middleware\GuestIdentitySensitiveMiddleware;
use plugin\SandIam\app\middleware\IdentityImportSensitiveMiddleware;
use plugin\SandIam\app\middleware\SyncSensitiveMiddleware;
use plugin\SandIam\app\middleware\OAuthRegistrationSensitiveMiddleware;
use plugin\SandIam\app\middleware\RadiusSensitiveMiddleware;
use plugin\SandIam\app\middleware\InitializationSensitiveMiddleware;
use plugin\SandIam\app\middleware\OidcSigningKeySensitiveMiddleware;
use plugin\SandIam\app\middleware\PortalSensitiveResponseMiddleware;
use plugin\sandadmin\app\middleware\CheckAuth;
use plugin\sandadmin\app\middleware\CheckLogin;
use plugin\sandadmin\app\middleware\SystemLog;
use Webman\Route;

Route::group('/app/sand-iam/admin', static function (): void {
    foreach ([
        'organization' => OrganizationController::class,
        'application' => ApplicationController::class,
        'environment' => EnvironmentController::class,
        'client' => WorkloadClientController::class,
        'service' => ServiceController::class,
        'action' => ServiceActionController::class,
        'grant' => ServiceGrantController::class,
        'identity' => IdentityController::class,
        'auth-policy' => AuthPolicyController::class,
        'application-experience' => ApplicationExperienceController::class,
        'oauth-client' => OAuthClientController::class,
        'identity-provider' => IdentityProviderController::class,
        'identity-binding' => IdentityBindingController::class,
        'role' => RoleController::class,
        'user-type' => UserTypeController::class,
        'resource' => ResourceController::class,
        'policy' => PolicyController::class,
        'admin-organization-grant' => AdminOrganizationGrantController::class,
        'admin-application-grant' => AdminApplicationGrantController::class,
        'api-resource' => ApiResourceController::class,
        'application-business-action' => ApplicationBusinessActionController::class,
        'api-route-binding' => ApiRouteBindingController::class,
        'cas-service' => CasServiceController::class,
        'radius-nas' => RadiusNasController::class,
        'application-network-policy' => ApplicationNetworkPolicyController::class,
        'audit-retention-policy' => AuditRetentionPolicyController::class,
    ] as $segment => $controller) {
        Route::get('/' . $segment . '/index', [$controller, 'index']);
        Route::get('/' . $segment . '/read', [$controller, 'read']);
        Route::post('/' . $segment . '/save', [$controller, 'save']);
        Route::post('/' . $segment . '/update', [$controller, 'update']);
        Route::post('/' . $segment . '/disable', [$controller, 'disable']);
    }
    Route::post('/grant/revoke', [ServiceGrantController::class, 'revoke']);
    Route::post('/policy/publish', [PolicyController::class, 'publish']);
    Route::post('/policy/rollback', [PolicyController::class, 'rollback']);
    Route::post('/policy/simulate', [PolicyController::class, 'simulate']);
    Route::post('/policy/revoke', [PolicyController::class, 'revoke']);
    Route::post('/application-business-action/publish', [ApplicationBusinessActionController::class, 'publish']);
    Route::get('/application-business-action/pending-claims', [ApplicationBusinessActionController::class, 'pendingClaims']);
    Route::get('/identity-role/index', [IdentityRoleController::class, 'index']);
    Route::post('/identity-role/grant', [IdentityRoleController::class, 'grant']);
    Route::post('/identity-role/revoke', [IdentityRoleController::class, 'revoke']);
    Route::get('/identity-user-type/index', [IdentityUserTypeController::class, 'index']);
    Route::post('/identity-user-type/grant', [IdentityUserTypeController::class, 'grant']);
    Route::post('/identity-user-type/revoke', [IdentityUserTypeController::class, 'revoke']);
    Route::get('/audit/index', [AuditLogController::class, 'index']);
    Route::get('/audit/read', [AuditLogController::class, 'read']);
    Route::get('/audit/export', [AuditLogController::class, 'export']);
    Route::get('/audit/archive/index', [AuditLogController::class, 'archiveIndex']);
    Route::get('/audit/archive/read', [AuditLogController::class, 'archiveRead']);
    Route::get('/security-alert/index', [SecurityAlertController::class, 'index']);
    Route::get('/security-alert/read', [SecurityAlertController::class, 'read']);
    Route::post('/security-alert/resolve', [SecurityAlertController::class, 'resolve']);
    Route::get('/initialization/index', [InitializationController::class, 'index']);
    Route::get('/initialization/read', [InitializationController::class, 'read']);
    Route::get('/initialization/draft-index', [InitializationController::class, 'draftIndex']);
    Route::get('/initialization/draft-read', [InitializationController::class, 'draftRead']);
    Route::get('/initialization/export', [InitializationController::class, 'export']);
    Route::get('/credential/index', [CredentialController::class, 'index']);
    Route::get('/credential/read', [CredentialController::class, 'read']);
    Route::post('/credential/issue', [CredentialController::class, 'issue']);
    Route::post('/credential/rotate', [CredentialController::class, 'rotate']);
    Route::post('/credential/revoke', [CredentialController::class, 'revoke']);
    Route::post('/oauth-client/secret/rotate', [OAuthClientController::class, 'rotateSecret']);
    Route::get('/oauth-client/logout-delivery/index', [OAuthClientController::class, 'logoutDeliveries']);
    Route::post('/oauth-client/logout-delivery/reissue', [OAuthClientController::class, 'reissueLogoutDelivery']);
    Route::post('/federation/provider/create', [FederationAdminController::class, 'createProvider']);
    Route::get('/identity-provider-preset/index', [IdentityProviderPresetController::class, 'index']);
    Route::get('/identity-provider-preset/read', [IdentityProviderPresetController::class, 'read']);
    Route::post('/federation/mount', [FederationAdminController::class, 'mount']);
    Route::post('/federation/sync', [FederationAdminController::class, 'sync']);
    Route::post('/scim/token/issue', [FederationAdminController::class, 'issueScimToken']);
    Route::get('/scim/token/index', [FederationAdminController::class, 'listScimTokens']);
    Route::get('/webhook/index', [WebhookController::class, 'index']);
    Route::get('/webhook/read', [WebhookController::class, 'read']);
    Route::post('/webhook/save', [WebhookController::class, 'save']);
    Route::post('/webhook/update', [WebhookController::class, 'update']);
    Route::post('/webhook/disable', [WebhookController::class, 'disable']);
    Route::post('/webhook/secret/rotate', [WebhookController::class, 'rotateSecret']);
    Route::get('/webhook/delivery/index', [WebhookController::class, 'deliveries']);
    Route::get('/webhook/delivery/read', [WebhookController::class, 'readDelivery']);
    Route::post('/webhook/delivery/retry', [WebhookController::class, 'retryDelivery']);
    Route::get('/admin-application-grant/admin-options', [AdminApplicationGrantController::class, 'adminOptions']);
    Route::post('/acceptance-fixture/cleanup', [AcceptanceFixtureController::class, 'cleanup']);
    Route::post('/acceptance-fixture/webhook-event', [AcceptanceFixtureController::class, 'webhookEvent']);
    Route::get('/acceptance-fixture/status', [AcceptanceFixtureController::class, 'status']);
    Route::get('/message-provider/index', [MessageProviderController::class, 'index']);
    Route::get('/message-provider/read', [MessageProviderController::class, 'read']);
    Route::post('/message-provider/save', [MessageProviderController::class, 'save']);
    Route::post('/message-provider/update', [MessageProviderController::class, 'update']);
    Route::post('/message-provider/disable', [MessageProviderController::class, 'disable']);
    Route::post('/message-provider/mount', [MessageProviderController::class, 'mount']);
    Route::post('/message-provider/unmount', [MessageProviderController::class, 'unmount']);
    Route::get('/message-provider/options', [MessageProviderController::class, 'options']);
    Route::get('/message-provider/mounts', [MessageProviderController::class, 'mounts']);
    Route::post('/identity/delete', [IdentityController::class, 'delete']);
    Route::post('/identity/enable', [IdentityController::class, 'enable']);
    Route::post('/identity/restore', [IdentityController::class, 'restore']);
    Route::get('/identity-group/index', [IdentityGroupController::class, 'index']);
    Route::get('/identity-group/read', [IdentityGroupController::class, 'read']);
    Route::post('/identity-group/save', [IdentityGroupController::class, 'save']);
    Route::post('/identity-group/update', [IdentityGroupController::class, 'update']);
    Route::post('/identity-group/disable', [IdentityGroupController::class, 'disable']);
    Route::get('/identity-group/members', [IdentityGroupController::class, 'members']);
    Route::post('/identity-group/member/add', [IdentityGroupController::class, 'addMember']);
    Route::post('/identity-group/member/remove', [IdentityGroupController::class, 'removeMember']);
    Route::get('/identity-group-role/index', [IdentityGroupRoleController::class, 'index']);
    Route::get('/identity-group-role/role-index', [IdentityGroupRoleController::class, 'roleIndex']);
    Route::post('/identity-group-role/grant', [IdentityGroupRoleController::class, 'grant']);
    Route::post('/identity-group-role/revoke', [IdentityGroupRoleController::class, 'revoke']);
    Route::get('/identity-invitation/index', [IdentityInvitationController::class, 'index']);
    Route::get('/identity-invitation/read', [IdentityInvitationController::class, 'read']);
    Route::post('/identity-invitation/resend', [IdentityInvitationController::class, 'resend']);
    Route::post('/identity-invitation/revoke', [IdentityInvitationController::class, 'revoke']);
    Route::get('/identity-import/index', [IdentityImportController::class, 'index']);
    Route::get('/identity-import/rows', [IdentityImportController::class, 'rows']);
    Route::post('/identity-import/confirm', [IdentityImportController::class, 'confirm']);
    Route::get('/identity-export/masked', [IdentityImportController::class, 'exportMasked']);
    Route::get('/identity-export/sensitive', [IdentityImportController::class, 'exportSensitive']);
    Route::get('/oauth-registration-token/index', [OAuthRegistrationTokenController::class, 'index']);
    Route::post('/oauth-registration-token/revoke', [OAuthRegistrationTokenController::class, 'revoke']);
    Route::get('/sync-connector/index', [SyncConnectorController::class, 'index']);
    Route::get('/sync-connector/read', [SyncConnectorController::class, 'read']);
    Route::post('/sync-connector/save', [SyncConnectorController::class, 'save']);
    Route::post('/sync-connector/update', [SyncConnectorController::class, 'update']);
    Route::post('/sync-connector/disable', [SyncConnectorController::class, 'disable']);
    Route::post('/sync-connector/run', [SyncConnectorController::class, 'run']);
    Route::get('/sync-connector/runs', [SyncConnectorController::class, 'runs']);
    Route::get('/sync-connector/outbox', [SyncConnectorController::class, 'outbox']);
    Route::post('/sync-connector/outbox-retry', [SyncConnectorController::class, 'retryOutbox']);
    Route::get('/developer/openapi', [DeveloperController::class, 'openApi']);
    Route::get('/developer/events', [DeveloperController::class, 'events']);
})->middleware([CheckLogin::class, CheckAuth::class, SystemLog::class]);

// Provider secrets are nested configuration values. Do not pass this endpoint
// through the host SystemLog middleware, which records request bodies.
Route::post('/app/sand-iam/admin/federation/configure', [FederationAdminController::class, 'configure'])->middleware([FederationSensitiveConfigMiddleware::class, CheckLogin::class, CheckAuth::class]);
Route::post('/app/sand-iam/admin/identity-provider-preset/draft', [IdentityProviderPresetController::class, 'draft'])->middleware([FederationSensitiveConfigMiddleware::class, CheckLogin::class, CheckAuth::class]);
Route::post('/app/sand-iam/admin/scim/token/revoke', [FederationAdminController::class, 'revokeScimToken'])->middleware([FederationSensitiveConfigMiddleware::class, CheckLogin::class, CheckAuth::class]);
Route::post('/app/sand-iam/admin/message-provider/configure', [MessageProviderController::class, 'configure'])->middleware([MessageProviderSensitiveMiddleware::class, CheckLogin::class, CheckAuth::class]);
Route::post('/app/sand-iam/admin/message-provider/test', [MessageProviderController::class, 'test'])->middleware([MessageProviderSensitiveMiddleware::class, CheckLogin::class, CheckAuth::class]);
Route::post('/app/sand-iam/admin/identity-invitation/send', [IdentityInvitationController::class, 'send'])->middleware([InvitationSensitiveMiddleware::class, CheckLogin::class, CheckAuth::class]);
Route::post('/app/sand-iam/admin/identity-import/preview', [IdentityImportController::class, 'preview'])->middleware([IdentityImportSensitiveMiddleware::class, CheckLogin::class, CheckAuth::class]);
Route::post('/app/sand-iam/admin/sync-connector/configure', [SyncConnectorController::class, 'configure'])->middleware([SyncSensitiveMiddleware::class, CheckLogin::class, CheckAuth::class]);
Route::post('/app/sand-iam/admin/sync-connector/test', [SyncConnectorController::class, 'test'])->middleware([SyncSensitiveMiddleware::class, CheckLogin::class, CheckAuth::class]);
Route::post('/app/sand-iam/admin/oauth-registration-token/issue', [OAuthRegistrationTokenController::class, 'issue'])->middleware([OAuthRegistrationSensitiveMiddleware::class, CheckLogin::class, CheckAuth::class]);
Route::post('/app/sand-iam/admin/radius-nas/configure', [RadiusNasController::class, 'configure'])->middleware([RadiusSensitiveMiddleware::class, CheckLogin::class, CheckAuth::class]);
Route::post('/app/sand-iam/admin/initialization/preview', [InitializationController::class, 'preview'])->middleware([InitializationSensitiveMiddleware::class, CheckLogin::class, CheckAuth::class]);
Route::post('/app/sand-iam/admin/initialization/apply', [InitializationController::class, 'apply'])->middleware([InitializationSensitiveMiddleware::class, CheckLogin::class, CheckAuth::class]);
Route::post('/app/sand-iam/admin/initialization/rollback', [InitializationController::class, 'rollback'])->middleware([InitializationSensitiveMiddleware::class, CheckLogin::class, CheckAuth::class]);
Route::post('/app/sand-iam/admin/initialization/save', [InitializationController::class, 'save'])->middleware([InitializationSensitiveMiddleware::class, CheckLogin::class, CheckAuth::class]);
Route::post('/app/sand-iam/admin/initialization/update', [InitializationController::class, 'update'])->middleware([InitializationSensitiveMiddleware::class, CheckLogin::class, CheckAuth::class]);
Route::post('/app/sand-iam/admin/initialization/disable', [InitializationController::class, 'disable'])->middleware([InitializationSensitiveMiddleware::class, CheckLogin::class, CheckAuth::class]);
Route::get('/app/sand-iam/admin/oidc-signing-key/index', [OidcSigningKeyController::class, 'index'])->middleware([OidcSigningKeySensitiveMiddleware::class, CheckLogin::class, CheckAuth::class, SystemLog::class]);
Route::get('/app/sand-iam/admin/oidc-signing-key/status', [OidcSigningKeyController::class, 'status'])->middleware([OidcSigningKeySensitiveMiddleware::class, CheckLogin::class, CheckAuth::class, SystemLog::class]);
Route::post('/app/sand-iam/admin/oidc-signing-key/rotate', [OidcSigningKeyController::class, 'rotate'])->middleware([OidcSigningKeySensitiveMiddleware::class, CheckLogin::class, CheckAuth::class, SystemLog::class]);
Route::post('/app/sand-iam/admin/oidc-signing-key/retire', [OidcSigningKeyController::class, 'retire'])->middleware([OidcSigningKeySensitiveMiddleware::class, CheckLogin::class, CheckAuth::class, SystemLog::class]);
Route::post('/app/sand-iam/admin/developer/onboarding/preview', [DeveloperController::class, 'onboardingPreview'])->middleware([InitializationSensitiveMiddleware::class, CheckLogin::class, CheckAuth::class]);
Route::post('/app/sand-iam/admin/developer/onboarding/apply', [DeveloperController::class, 'onboardingApply'])->middleware([InitializationSensitiveMiddleware::class, CheckLogin::class, CheckAuth::class]);

Route::post('/app/sand-iam/runtime/context/issue', [RuntimeContextController::class, 'issue']);
Route::post('/app/sand-iam/runtime/context/verify', [RuntimeContextController::class, 'verify']);

Route::post('/api/sand-iam/v1/authorization/decide', [AuthorizationController::class, 'decide']);
Route::get('/api/sand-iam/v1/experience', [ExperienceController::class, 'read']);
Route::get('/app/sand-iam/account/', [AccountPortalController::class, 'index']);
Route::get('/app/sand-iam/account/account.js', [AccountPortalController::class, 'script']);
Route::post('/api/sand-iam/v1/invitations/accept', [InvitationController::class, 'accept'])->middleware([InvitationSensitiveMiddleware::class]);
Route::post('/api/sand-iam/v1/guests/upsert', [GuestIdentityController::class, 'upsert'])->middleware([GuestIdentitySensitiveMiddleware::class]);

Route::group('/api/sand-iam/v1/auth', static function (): void {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/password/forgot', [AuthController::class, 'forgotPassword']);
    Route::post('/password/reset', [AuthController::class, 'resetPassword']);
    Route::post('/password/change', [AuthController::class, 'changePassword']);
    Route::post('/verification/request', [AuthController::class, 'requestVerification']);
    Route::post('/verification/confirm', [AuthController::class, 'confirmVerification']);
    Route::get('/sessions', [AuthController::class, 'sessions']);
    Route::post('/sessions/revoke', [AuthController::class, 'revokeSession']);
    Route::get('/mfa/factors', [AuthController::class, 'mfaFactors']);
    Route::post('/mfa/totp/start', [AuthController::class, 'totpStart']);
    Route::post('/mfa/totp/confirm', [AuthController::class, 'totpConfirm']);
    Route::post('/mfa/factors/rename', [AuthController::class, 'mfaRename']);
    Route::post('/mfa/factors/revoke', [AuthController::class, 'mfaRevoke']);
    Route::post('/mfa/recovery/regenerate', [AuthController::class, 'recoveryRegenerate']);
    Route::post('/mfa/challenge/verify', [AuthController::class, 'mfaChallengeVerify']);
    Route::post('/step-up/password', [AuthController::class, 'stepUpPassword']);
    Route::post('/step-up/mfa/start', [AuthController::class, 'stepUpMfaStart']);
    Route::post('/federation/unlink', [AuthController::class, 'federationUnlink']);
    Route::post('/passkeys/registration/options', [AuthController::class, 'passkeyRegistrationOptions']);
    Route::post('/passkeys/registration/finish', [AuthController::class, 'passkeyRegistrationFinish']);
    Route::post('/passkeys/authentication/options', [AuthController::class, 'passkeyAuthenticationOptions']);
    Route::post('/passkeys/authentication/finish', [AuthController::class, 'passkeyAuthenticationFinish']);
})->middleware([PortalSensitiveResponseMiddleware::class]);

Route::get('/api/sand-iam/v1/me/profile', [SelfServiceController::class, 'profile'])->middleware([PortalSensitiveResponseMiddleware::class]);
Route::patch('/api/sand-iam/v1/me/profile', [SelfServiceController::class, 'updateProfile'])->middleware([PortalSensitiveResponseMiddleware::class]);
Route::get('/api/sand-iam/v1/me/connections', [SelfServiceController::class, 'connections'])->middleware([PortalSensitiveResponseMiddleware::class]);
Route::get('/api/sand-iam/v1/me/security', [SelfServiceController::class, 'security'])->middleware([PortalSensitiveResponseMiddleware::class]);

Route::get('/api/sand-iam/v1/oauth/authorize', [OAuthOidcController::class, 'authorize']);
Route::post('/api/sand-iam/v1/oauth/authorize', [OAuthOidcController::class, 'authorize']);
Route::get('/api/sand-iam/v1/oauth/interaction', [OAuthOidcController::class, 'interaction'])->middleware([PortalSensitiveResponseMiddleware::class]);
Route::post('/api/sand-iam/v1/oauth/interaction/session', [OAuthOidcController::class, 'interactionSession'])->middleware([PortalSensitiveResponseMiddleware::class]);
Route::post('/api/sand-iam/v1/oauth/interaction/confirm', [OAuthOidcController::class, 'interactionConfirm'])->middleware([PortalSensitiveResponseMiddleware::class]);
Route::post('/api/sand-iam/v1/oauth/token', [OAuthOidcController::class, 'token']);
Route::get('/api/sand-iam/v1/oauth/userinfo', [OAuthOidcController::class, 'userinfo']);
Route::post('/api/sand-iam/v1/oauth/userinfo', [OAuthOidcController::class, 'userinfo']);
Route::post('/api/sand-iam/v1/oauth/revoke', [OAuthOidcController::class, 'revoke']);
Route::post('/api/sand-iam/v1/oauth/register', [OAuthOidcController::class, 'register']);
Route::get('/api/sand-iam/v1/oauth/logout', [OAuthOidcController::class, 'logout']);
Route::post('/api/sand-iam/v1/oauth/logout', [OAuthOidcController::class, 'logout']);
Route::get('/api/sand-iam/v1/.well-known/openid-configuration', [OAuthOidcController::class, 'discovery']);
Route::get('/api/sand-iam/v1/.well-known/jwks.json', [OAuthOidcController::class, 'jwks']);

Route::get('/api/sand-iam/v1/cas/login', [CasController::class, 'login']);
Route::get('/api/sand-iam/v1/cas/interaction', [CasController::class, 'interaction']);
Route::post('/api/sand-iam/v1/cas/interaction/confirm', [CasController::class, 'confirm']);
Route::post('/api/sand-iam/v1/cas/interaction/reject', [CasController::class, 'reject']);
Route::get('/api/sand-iam/v1/cas/validate', [CasController::class, 'validate']);
Route::get('/api/sand-iam/v1/cas/serviceValidate', [CasController::class, 'serviceValidate']);
Route::get('/api/sand-iam/v1/cas/p3/serviceValidate', [CasController::class, 'p3ServiceValidate']);
Route::post('/api/sand-iam/v1/federation/kerberos/negotiate', [KerberosController::class, 'negotiate']);

Route::get('/api/sand-iam/v1/federation/oidc/start', [FederationController::class, 'oidcStart'])->middleware([PortalSensitiveResponseMiddleware::class, FederationProtocolMiddleware::class]);
Route::get('/api/sand-iam/v1/federation/oidc/callback', [FederationController::class, 'oidcCallback'])->middleware([PortalSensitiveResponseMiddleware::class, FederationProtocolMiddleware::class]);
Route::get('/api/sand-iam/v1/federation/oauth2/start', [FederationController::class, 'oauth2Start'])->middleware([PortalSensitiveResponseMiddleware::class, FederationProtocolMiddleware::class]);
Route::get('/api/sand-iam/v1/federation/oauth2/callback', [FederationController::class, 'oauth2Callback'])->middleware([PortalSensitiveResponseMiddleware::class, FederationProtocolMiddleware::class]);
Route::get('/api/sand-iam/v1/federation/saml/start', [FederationController::class, 'samlStart'])->middleware([PortalSensitiveResponseMiddleware::class, FederationProtocolMiddleware::class]);
Route::post('/api/sand-iam/v1/federation/saml/acs', [FederationController::class, 'samlAcs'])->middleware([PortalSensitiveResponseMiddleware::class, FederationProtocolMiddleware::class]);
Route::post('/api/sand-iam/v1/federation/handoff/exchange', [FederationController::class, 'exchange'])->middleware([PortalSensitiveResponseMiddleware::class, FederationProtocolMiddleware::class]);
Route::get('/api/sand-iam/v1/scim/{provider}/ServiceProviderConfig', [ScimController::class, 'serviceProviderConfig'])->middleware([ScimProtocolMiddleware::class]);
Route::get('/api/sand-iam/v1/scim/{provider}/Schemas', [ScimController::class, 'schemas'])->middleware([ScimProtocolMiddleware::class]);
Route::get('/api/sand-iam/v1/scim/{provider}/ResourceTypes', [ScimController::class, 'resourceTypes'])->middleware([ScimProtocolMiddleware::class]);
Route::get('/api/sand-iam/v1/scim/{provider}/Users', [ScimController::class, 'users'])->middleware([ScimProtocolMiddleware::class]);
Route::post('/api/sand-iam/v1/scim/{provider}/Users', [ScimController::class, 'users'])->middleware([ScimProtocolMiddleware::class]);
Route::get('/api/sand-iam/v1/scim/{provider}/Users/{id}', [ScimController::class, 'user'])->middleware([ScimProtocolMiddleware::class]);
Route::patch('/api/sand-iam/v1/scim/{provider}/Users/{id}', [ScimController::class, 'user'])->middleware([ScimProtocolMiddleware::class]);
Route::put('/api/sand-iam/v1/scim/{provider}/Users/{id}', [ScimController::class, 'user'])->middleware([ScimProtocolMiddleware::class]);
Route::delete('/api/sand-iam/v1/scim/{provider}/Users/{id}', [ScimController::class, 'user'])->middleware([ScimProtocolMiddleware::class]);
Route::get('/api/sand-iam/v1/scim/{provider}/Groups', [ScimController::class, 'groups'])->middleware([ScimProtocolMiddleware::class]);
Route::post('/api/sand-iam/v1/scim/{provider}/Groups', [ScimController::class, 'groups'])->middleware([ScimProtocolMiddleware::class]);
Route::get('/api/sand-iam/v1/scim/{provider}/Groups/{id}', [ScimController::class, 'group'])->middleware([ScimProtocolMiddleware::class]);
Route::patch('/api/sand-iam/v1/scim/{provider}/Groups/{id}', [ScimController::class, 'group'])->middleware([ScimProtocolMiddleware::class]);
Route::put('/api/sand-iam/v1/scim/{provider}/Groups/{id}', [ScimController::class, 'group'])->middleware([ScimProtocolMiddleware::class]);
Route::delete('/api/sand-iam/v1/scim/{provider}/Groups/{id}', [ScimController::class, 'group'])->middleware([ScimProtocolMiddleware::class]);

// The package identifier contains a hyphen, which Webman's plugin-wide
// disableDefaultRoute form does not accept. Disable every explicit controller
// so no alternate default URL can bypass the intended middleware chain.
// Keep these security-critical guards explicit: their contract tests protect
// the authorization and self-service entrypoints from alternate default URLs.
Route::disableDefaultRoute(AuthorizationController::class);
Route::disableDefaultRoute(SelfServiceController::class);
foreach ([
    ApplicationController::class,
    AdminOrganizationGrantController::class,
    AdminApplicationGrantController::class,
    AcceptanceFixtureController::class,
    AuditLogController::class,
    AuthPolicyController::class,
    ApplicationExperienceController::class,
    MessageProviderController::class,
    IdentityGroupController::class,
    IdentityGroupRoleController::class,
    IdentityInvitationController::class,
    IdentityImportController::class,
    SyncConnectorController::class,
    OAuthClientController::class,
    OidcSigningKeyController::class,
    OAuthRegistrationTokenController::class,
    CasServiceController::class,
    RadiusNasController::class,
    DeveloperController::class,
    ApplicationNetworkPolicyController::class,
    AuditRetentionPolicyController::class,
    SecurityAlertController::class,
    InitializationController::class,
    CredentialController::class,
    EnvironmentController::class,
    IdentityController::class,
    IdentityBindingController::class,
    IdentityProviderController::class,
    IdentityProviderPresetController::class,
    IdentityRoleController::class,
    IdentityUserTypeController::class,
    OrganizationController::class,
    PolicyController::class,
    ResourceController::class,
    RoleController::class,
    ServiceActionController::class,
    ServiceController::class,
    ServiceGrantController::class,
    UserTypeController::class,
    WorkloadClientController::class,
    FederationAdminController::class,
    ApiResourceController::class,
    ApplicationBusinessActionController::class,
    ApiRouteBindingController::class,
    WebhookController::class,
    RuntimeContextController::class,
    AuthController::class,
    OAuthOidcController::class,
    FederationController::class,
    ScimController::class,
    ExperienceController::class,
    InvitationController::class,
    GuestIdentityController::class,
    CasController::class,
    KerberosController::class,
    AccountPortalController::class,
] as $explicitController) {
    Route::disableDefaultRoute($explicitController);
}
