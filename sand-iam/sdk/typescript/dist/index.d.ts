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
    username: string;
    password: string;
    displayName?: string;
    email?: string;
    phone?: string;
    userAgent?: string;
    requestId?: string;
}
export interface LoginInput {
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
    login(input: LoginInput): Promise<SandIamAuthResult>;
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
