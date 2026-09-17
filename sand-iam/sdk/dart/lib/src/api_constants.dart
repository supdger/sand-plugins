abstract final class SandIamApi {
  static const authorizationDecision = '/api/sand-iam/v1/authorization/decide';
  static const runtimeContextIssue = '/app/sand-iam/runtime/context/issue';
  static const runtimeContextVerify = '/app/sand-iam/runtime/context/verify';
  static const register = '/api/sand-iam/v1/auth/register';
  static const acceptInvitation = '/api/sand-iam/v1/invitations/accept';
  static const login = '/api/sand-iam/v1/auth/login';
  static const verifyMfaChallenge = '/api/sand-iam/v1/auth/mfa/challenge/verify';
  static const passkeyRegistrationOptions = '/api/sand-iam/v1/auth/passkeys/registration/options';
  static const passkeyRegistrationFinish = '/api/sand-iam/v1/auth/passkeys/registration/finish';
  static const passkeyAuthenticationOptions = '/api/sand-iam/v1/auth/passkeys/authentication/options';
  static const passkeyAuthenticationFinish = '/api/sand-iam/v1/auth/passkeys/authentication/finish';
  static const mfaFactors = '/api/sand-iam/v1/auth/mfa/factors';
  static const captchaConfiguration = '/api/sand-iam/v1/auth/captcha/config';
  static const stepUpPassword = '/api/sand-iam/v1/auth/step-up/password';
  static const startMfaStepUp = '/api/sand-iam/v1/auth/step-up/mfa/start';
  static const unlinkFederation = '/api/sand-iam/v1/auth/federation/unlink';
  static const startTotp = '/api/sand-iam/v1/auth/mfa/totp/start';
  static const confirmTotp = '/api/sand-iam/v1/auth/mfa/totp/confirm';
  static const renameMfaFactor = '/api/sand-iam/v1/auth/mfa/factors/rename';
  static const revokeMfaFactor = '/api/sand-iam/v1/auth/mfa/factors/revoke';
  static const regenerateRecoveryCodes = '/api/sand-iam/v1/auth/mfa/recovery/regenerate';
  static const forgotPassword = '/api/sand-iam/v1/auth/password/forgot';
  static const requestVerification = '/api/sand-iam/v1/auth/verification/request';
  static const confirmVerification = '/api/sand-iam/v1/auth/verification/confirm';
  static const resetPassword = '/api/sand-iam/v1/auth/password/reset';
  static const refresh = '/api/sand-iam/v1/auth/refresh';
  static const logout = '/api/sand-iam/v1/auth/logout';
  static const sessions = '/api/sand-iam/v1/auth/sessions';
  static const revokeSession = '/api/sand-iam/v1/auth/sessions/revoke';
  static const changePassword = '/api/sand-iam/v1/auth/password/change';
  static const profile = '/api/sand-iam/v1/me/profile';
  static const connections = '/api/sand-iam/v1/me/connections';
  static const security = '/api/sand-iam/v1/me/security';
  static const experience = '/api/sand-iam/v1/experience';
  static const discovery = '/api/sand-iam/v1/.well-known/openid-configuration';
  static const onboardingPreview = '/app/sand-iam/admin/developer/onboarding/preview';
  static const onboardingApply = '/app/sand-iam/admin/developer/onboarding/apply';
  static const routeManifestPreview =
      '/app/sand-iam/admin/developer/route-manifest/preview';
  static const routeManifestApply =
      '/app/sand-iam/admin/developer/route-manifest/apply';
  static const openApiImportPreview =
      '/app/sand-iam/admin/developer/openapi-import/preview';
  static const openApiImportApply =
      '/app/sand-iam/admin/developer/openapi-import/apply';
  static const policySimulate = '/app/sand-iam/admin/policy/simulate';
  static const policyRollback = '/app/sand-iam/admin/policy/rollback';
  static const credentialIssue = '/app/sand-iam/admin/credential/issue';
  static const credentialRotate = '/app/sand-iam/admin/credential/rotate';
  static const credentialRevoke = '/app/sand-iam/admin/credential/revoke';
  static const providerPresetList = '/app/sand-iam/admin/identity-provider-preset/index';
  static const providerPresetDraft = '/app/sand-iam/admin/identity-provider-preset/draft';
}
