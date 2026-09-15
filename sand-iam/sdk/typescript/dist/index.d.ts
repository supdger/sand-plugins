export type SandIamScope = Record<string, unknown>;
export * from './management.js';
export interface SandIamDecision {
    allowed: boolean;
    code: string;
    policy_ids: number[];
    scope: SandIamScope;
    application_id: number;
    identity_id: number;
    api_code: string;
    api_version: string;
    resource_code: string;
    action: string;
    operation: 'list' | 'read' | 'create' | 'update' | 'delete' | 'export' | 'batch';
    risk_level: 'low' | 'medium' | 'high' | 'critical';
}
export interface DecideInput {
    apiCode: string;
    apiVersion?: string;
    attributes?: Record<string, unknown>;
    requestId?: string;
}
export interface IssueContextInput {
    credential: string;
    serviceCode: string;
    audience: string;
    actions: readonly string[];
    subjectScope?: Record<string, unknown> | undefined;
    requestId?: string | undefined;
}
export interface VerifyContextInput {
    context: string;
    serviceCode: string;
    audience: string;
    actions: readonly string[];
    /** 仅供服务端适配器保留；公开 HTTP 路由从可信连接对端解析，绝不序列化该值。 */
    sourceIp?: string | undefined;
    requestId?: string | undefined;
}
export interface SandIamWorkloadContext {
    context?: string | undefined;
    context_id: string;
    expire_time?: string | undefined;
    service_code?: string | undefined;
    audience?: string | undefined;
    actions?: string[] | undefined;
    [claim: string]: unknown;
}
export type SandIamWorkloadErrorCode = 'SAND_IAM_SERVICE_NETWORK_FORBIDDEN' | 'SAND_IAM_INVOCATION_FACTS_UNVERIFIED' | 'SAND_IAM_DATA_CLASS_FORBIDDEN' | 'SAND_IAM_SERVICE_QUOTA_EXCEEDED' | 'SAND_IAM_IDEMPOTENCY_CONFLICT';
export interface RegisterInput {
    captchaToken?: string;
    username: string;
    password: string;
    displayName?: string;
    email?: string;
    phone?: string;
    userAgent?: string;
    requestId?: string;
}
export interface LoginInput {
    captchaToken?: string;
    identifier: string;
    password: string;
    userAgent?: string;
    requestId?: string;
}
export interface SandIamIdentitySummary {
    id: number;
    code?: string | undefined;
    display_name: string;
}
export interface SandIamAuthResult {
    step_up?: boolean | undefined;
    methods?: string[] | undefined;
    expires_in?: number | undefined;
    public_key?: Record<string, unknown> | undefined;
    identity?: SandIamIdentitySummary | undefined;
    access_token?: string | undefined;
    refresh_token?: string | undefined;
    session_id?: number | undefined;
    access_expire_time?: string | undefined;
    refresh_expire_time?: string | undefined;
    verification_required?: boolean | undefined;
    mfa_required?: boolean | undefined;
    challenge_token?: string | undefined;
}
export interface SandIamPasskeyOptions {
    challenge_token: string;
    public_key: Record<string, unknown>;
}
export interface SandIamCaptchaWidget {
    kind: 'turnstile';
    site_key: string;
    action: 'login' | 'register';
    application_binding: string;
}
export type SandIamCaptchaConfiguration = {
    required: false;
} | {
    required: true;
    available: false;
} | {
    required: true;
    available: true;
    widget: SandIamCaptchaWidget;
};
export interface PasskeyRegistrationResponse {
    clientDataJSON: string;
    attestationObject: string;
}
export interface PasskeyAuthenticationResponse {
    clientDataJSON: string;
    authenticatorData: string;
    signature: string;
    userHandle: string;
}
export interface SandIamMfaFactor {
    id: number;
    type: 'totp' | 'passkey';
    name: string;
    status: number;
    create_time: string | null;
    last_used_time: string | null;
}
export interface SandIamTotpSetup {
    factor_id: number;
    secret?: string;
    otpauth_uri?: string;
    secret_available?: boolean;
}
export interface SandIamRecoveryCodes {
    recovery_codes?: string[];
    secret_available?: boolean;
}
export interface SandIamTotpConfirmation extends SandIamRecoveryCodes {
    enabled: true;
}
export type VerifyMfaChallengeInput = {
    challengeToken: string;
    requestId?: string;
    userAgent?: string;
} & ({
    method: 'totp' | 'recovery_code';
    code: string;
} | {
    method: 'passkey';
    rawId: string;
    response: {
        clientDataJSON: string;
        authenticatorData: string;
        signature: string;
        userHandle?: string | null;
    };
});
export interface ForgotPasswordInput {
    identifier: string;
    channel: 'email' | 'phone';
    requestId?: string;
}
export interface ResetPasswordInput extends ForgotPasswordInput {
    code: string;
    password: string;
}
export interface VerificationInput {
    identifier: string;
    channel: 'email' | 'phone';
    requestId?: string;
}
export interface ConfirmVerificationInput extends VerificationInput {
    code: string;
}
export interface SandIamProfile {
    identity_id: number;
    display_name: string;
    organization: {
        code: string;
        name: string;
    };
    application: {
        code: string;
        name: string;
    };
    create_time?: string | null;
}
export interface SandIamSecurityOverview {
    password_enabled: boolean;
    active_sessions: number;
    totp_factors: number;
    passkeys: number;
    connected_accounts: number;
}
export interface SandIamConnection {
    binding_id: number;
    provider_name: string;
    provider_type: string;
    account_hint: string;
    source_state: string;
    linked_time?: string | null;
}
export interface SandIamSession {
    id: number;
    create_time?: string | null;
    last_used_time?: string | null;
    access_expire_time?: string | null;
    refresh_expire_time?: string | null;
    current: boolean;
}
export interface SandIamClientOptions {
    baseUrl: string;
    organizationCode: string;
    applicationCode: string;
    accessToken: () => string | Promise<string>;
    fetch?: typeof globalThis.fetch;
}
export declare class SandIamError extends Error {
    readonly code: string;
    readonly status: number;
    constructor(code: string, message: string, status: number);
}
export declare class SandIamDeniedError extends SandIamError {
    readonly decision: SandIamDecision;
    constructor(decision: SandIamDecision);
}
export declare class SandIamClient {
    private readonly options;
    private readonly fetcher;
    private readonly baseUrl;
    constructor(options: SandIamClientOptions);
    decide(input: DecideInput): Promise<SandIamDecision>;
    authorize(input: DecideInput): Promise<SandIamDecision>;
    issueContext(input: IssueContextInput): Promise<SandIamWorkloadContext>;
    verifyContext(input: VerifyContextInput): Promise<SandIamWorkloadContext>;
    register(input: RegisterInput): Promise<SandIamAuthResult>;
    /** Accepting an invitation does not start a session. */
    acceptInvitation(input: {
        token: string;
        username: string;
        password: string;
        displayName?: string;
        requestId?: string;
    }): Promise<{
        id: number;
        display_name: string;
    }>;
    login(input: LoginInput): Promise<SandIamAuthResult>;
    verifyMfaChallenge(input: VerifyMfaChallengeInput): Promise<SandIamAuthResult>;
    /** Platform code performs the WebAuthn ceremony and serializes binary fields. */
    passkeyRegistrationOptions(input: {
        name?: string;
        currentPassword: string;
        requestId?: string;
    }): Promise<SandIamPasskeyOptions>;
    passkeyRegistrationFinish(input: {
        challengeToken: string;
        rawId: string;
        response: PasskeyRegistrationResponse;
        requestId?: string;
    }): Promise<void>;
    passkeyAuthenticationOptions(requestId?: string): Promise<SandIamPasskeyOptions>;
    passkeyAuthenticationFinish(input: {
        challengeToken: string;
        rawId: string;
        response: PasskeyAuthenticationResponse;
        userAgent?: string;
        requestId?: string;
    }): Promise<SandIamAuthResult>;
    captchaConfiguration(action: 'login' | 'register', requestId?: string): Promise<SandIamCaptchaConfiguration>;
    stepUpPassword(password: string, requestId?: string): Promise<SandIamAuthResult>;
    startMfaStepUp(requestId?: string): Promise<SandIamAuthResult>;
    unlinkFederation(bindingId: number, requestId?: string): Promise<void>;
    mfaFactors(requestId?: string): Promise<SandIamMfaFactor[]>;
    startTotp(input: {
        name?: string;
        currentPassword: string;
        requestId?: string;
    }): Promise<SandIamTotpSetup>;
    confirmTotp(input: {
        factorId: number;
        code: string;
        requestId?: string;
    }): Promise<SandIamTotpConfirmation>;
    renameMfaFactor(input: {
        factorId: number;
        type: 'totp' | 'passkey';
        name: string;
        requestId?: string;
    }): Promise<void>;
    revokeMfaFactor(input: {
        factorId: number;
        type: 'totp' | 'passkey';
        password: string;
        requestId?: string;
    }): Promise<void>;
    regenerateRecoveryCodes(input: {
        password: string;
        requestId?: string;
    }): Promise<SandIamRecoveryCodes>;
    requestVerification(input: VerificationInput): Promise<void>;
    confirmVerification(input: ConfirmVerificationInput): Promise<void>;
    forgotPassword(input: ForgotPasswordInput): Promise<void>;
    resetPassword(input: ResetPasswordInput): Promise<void>;
    refresh(refreshToken: string, requestId?: string): Promise<SandIamAuthResult>;
    profile(requestId?: string): Promise<SandIamProfile>;
    updateProfile(displayName: string, requestId?: string): Promise<SandIamProfile>;
    securityOverview(requestId?: string): Promise<SandIamSecurityOverview>;
    connections(requestId?: string): Promise<SandIamConnection[]>;
    sessions(requestId?: string): Promise<SandIamSession[]>;
    revokeSession(sessionId: number, requestId?: string): Promise<void>;
    changePassword(currentPassword: string, newPassword: string, requestId?: string): Promise<void>;
    logout(requestId?: string): Promise<void>;
    private applicationPayload;
    private request;
}
