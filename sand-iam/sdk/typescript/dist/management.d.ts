/** SandAdmin management-plane SDK. This client never accepts workload credentials. */
export type SandIamManagementJson = Record<string, unknown>;
export interface SandIamManagementClientOptions {
    baseUrl: string;
    administratorToken: () => string | Promise<string>;
    fetch?: typeof globalThis.fetch;
}
export interface OnboardingApplyInput {
    manifest: SandIamManagementJson & {
        operation_id: string;
    };
    previewHash: string;
    requestId: string;
}
export interface RouteSyncApplyInput {
    manifest: SandIamManagementJson;
    previewHash: string;
    requestId: string;
    disableMissing?: boolean | undefined;
}
export interface CredentialIssueInput {
    workloadClientId: number;
    name: string;
    expireTime?: string | null | undefined;
    requestId: string;
}
export interface CredentialRotateInput {
    credentialId: number;
    name: string;
    expireTime?: string | null | undefined;
    requestId: string;
}
export interface CredentialRevokeInput {
    credentialId: number;
    requestId: string;
}
export interface ProviderPresetDraftInput {
    code: string;
    clientId: string;
    redirectUri: string;
    handoffReturnUris: readonly string[];
    tenantId?: string | undefined;
    requestId?: string | undefined;
}
export declare class SandIamManagementError extends Error {
    readonly code: string;
    readonly status: number;
    constructor(code: string, message: string, status: number);
}
/** One-time secret that is intentionally neither printable nor JSON serializable. */
export declare class SandIamOneTimeSecret {
    #private;
    constructor(value: string);
    get secretAvailable(): boolean;
    revealOnce(): string;
    toString(): never;
    toJSON(): {
        secret_available: boolean;
    };
}
export declare class SandIamCredentialResult {
    readonly metadata: SandIamManagementJson;
    readonly secretAvailable: boolean;
    private readonly secret;
    readonly replayed: boolean;
    constructor(metadata: SandIamManagementJson, secretAvailable: boolean, secret: SandIamOneTimeSecret | undefined, replayed: boolean);
    revealSecretOnce(): string;
    toString(): never;
    toJSON(): {
        metadata: SandIamManagementJson;
        secret_available: boolean;
        replayed: boolean;
    };
}
export declare class SandIamManagementClient {
    private readonly options;
    private readonly baseUrl;
    private readonly fetcher;
    constructor(options: SandIamManagementClientOptions);
    onboardingPreview(manifest: SandIamManagementJson, requestId?: string): Promise<SandIamManagementJson>;
    onboardingApply(input: OnboardingApplyInput): Promise<SandIamManagementJson>;
    routeSyncPreview(manifest: SandIamManagementJson, disableMissing?: boolean, requestId?: string): Promise<SandIamManagementJson>;
    routeSyncApply(input: RouteSyncApplyInput): Promise<SandIamManagementJson>;
    policySimulate(input: SandIamManagementJson, requestId?: string): Promise<SandIamManagementJson>;
    policyRollback(policyId: number, versionId: number, requestId: string): Promise<SandIamManagementJson>;
    credentialIssue(input: CredentialIssueInput): Promise<SandIamCredentialResult>;
    credentialRotate(input: CredentialRotateInput): Promise<SandIamCredentialResult>;
    credentialRevoke(input: CredentialRevokeInput): Promise<SandIamManagementJson>;
    presetList(requestId?: string): Promise<SandIamManagementJson[]>;
    presetDraft(input: ProviderPresetDraftInput): Promise<SandIamManagementJson>;
    private objectRequest;
    private listRequest;
    private request;
    private assertOperation;
    private assertRequestId;
    private credentialResult;
}
