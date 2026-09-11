/** Minimal browser-safe WebAuthn conversions for the portal API boundary. */

export interface SandIamWebAuthnOptions {
  readonly challenge: string;
  readonly rp?: { readonly id?: string; readonly name?: string };
  readonly user?: {
    readonly id: string;
    readonly name: string;
    readonly displayName: string;
  };
  readonly pubKeyCredParams?: readonly PublicKeyCredentialParameters[];
  readonly authenticatorSelection?: AuthenticatorSelectionCriteria;
  readonly attestation?: AttestationConveyancePreference;
  readonly timeout?: number;
}

export interface SandIamWebAuthnAssertionOptions {
  readonly challenge: string;
  readonly rpId: string;
  readonly allowCredentials: readonly PublicKeyCredentialDescriptor[];
  readonly userVerification: UserVerificationRequirement;
  readonly timeout?: number;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function readString(value: unknown): string | null {
  return typeof value === "string" && value.trim() !== "" ? value : null;
}

export function parseTotpStart(value: unknown): {
  readonly factorId: number;
  readonly secret: string;
  readonly otpAuthUri: string;
} | null {
  const payload = isRecord(value) && "data" in value ? value.data : value;
  if (!isRecord(payload)) return null;
  const factorId = payload.factor_id;
  const secret = readString(payload.secret);
  const otpAuthUri = readString(payload.otpauth_uri);
  if (!Number.isInteger(factorId) || typeof factorId !== "number" || factorId <= 0 || secret === null || otpAuthUri === null) {
    return null;
  }
  return { factorId, secret, otpAuthUri };
}

export function parseRecoveryCodes(value: unknown): readonly string[] | null {
  const payload = isRecord(value) && "data" in value ? value.data : value;
  if (!isRecord(payload) || !Array.isArray(payload.recovery_codes)) return null;
  const codes = payload.recovery_codes.filter(
    (item): item is string => typeof item === "string" && item.trim() !== "",
  );
  return codes.length === payload.recovery_codes.length ? codes : null;
}

export function parsePasskeyOptions(value: unknown): SandIamWebAuthnOptions | null {
  const payload = isRecord(value) && "data" in value ? value.data : value;
  if (!isRecord(payload) || !isRecord(payload.public_key)) return null;
  const options = payload.public_key;
  const challenge = readString(options.challenge);
  const user = isRecord(options.user) ? options.user : null;
  const userId = user === null ? null : readString(user.id);
  const userName = user === null ? null : readString(user.name);
  const displayName = user === null ? null : readString(user.displayName);
  if (challenge === null || userId === null || userName === null || displayName === null) return null;
  const rpId = isRecord(options.rp) ? readString(options.rp.id) : null;
  const rpName = isRecord(options.rp) ? readString(options.rp.name) : null;
  const rp = rpId === null && rpName === null
    ? undefined
    : {
        ...(rpId === null ? {} : { id: rpId }),
        ...(rpName === null ? {} : { name: rpName }),
      };
  const residentKey: ResidentKeyRequirement | undefined = isRecord(options.authenticatorSelection)
    && options.authenticatorSelection.residentKey === "required"
    ? "required"
    : undefined;
  const userVerification: UserVerificationRequirement = isRecord(options.authenticatorSelection)
    && (options.authenticatorSelection.userVerification === "required" ||
      options.authenticatorSelection.userVerification === "discouraged")
    ? options.authenticatorSelection.userVerification
    : "preferred";
  const authenticatorSelection: AuthenticatorSelectionCriteria | undefined = isRecord(options.authenticatorSelection)
    ? {
        ...(residentKey === undefined ? {} : { residentKey }),
        userVerification,
      }
    : undefined;
  return {
    challenge,
    user: { id: userId, name: userName, displayName },
    ...(rp === undefined ? {} : { rp }),
    ...(Array.isArray(options.pubKeyCredParams)
      ? {
          pubKeyCredParams: options.pubKeyCredParams.filter(isRecord).map((item) => ({
            type: item.type === "public-key" ? "public-key" : "public-key",
            alg: typeof item.alg === "number" ? item.alg : -7,
          })),
        }
      : {}),
    ...(authenticatorSelection === undefined ? {} : { authenticatorSelection }),
    ...(options.attestation === "direct" || options.attestation === "enterprise" || options.attestation === "none"
      ? { attestation: options.attestation }
      : {}),
    ...(typeof options.timeout === "number" && Number.isFinite(options.timeout)
      ? { timeout: options.timeout }
      : {}),
  };
}

export function parsePasskeyAssertionOptions(value: unknown): SandIamWebAuthnAssertionOptions | null {
  if (!isRecord(value)) return null;
  const challenge = readString(value.challenge);
  const rpId = readString(value.rpId);
  if (challenge === null || rpId === null) return null;
  const userVerification: UserVerificationRequirement =
    value.userVerification === "required" || value.userVerification === "discouraged"
      ? value.userVerification
      : "preferred";
  const allowCredentials = Array.isArray(value.allowCredentials)
    ? value.allowCredentials.flatMap((item): PublicKeyCredentialDescriptor[] => {
        if (!isRecord(item) || item.type !== "public-key") return [];
        const id = readString(item.id);
        return id === null ? [] : [{ type: "public-key", id: fromBase64Url(id) }];
      })
    : [];
  return {
    challenge,
    rpId,
    allowCredentials,
    userVerification,
    ...(typeof value.timeout === "number" && Number.isFinite(value.timeout)
      ? { timeout: value.timeout }
      : {}),
  };
}

function base64Url(bytes: ArrayBuffer): string {
  const binary = String.fromCharCode(...new Uint8Array(bytes));
  return btoa(binary).replaceAll("+", "-").replaceAll("/", "_").replace(/=+$/u, "");
}

function fromBase64Url(value: string): ArrayBuffer {
  const normalized = value.replaceAll("-", "+").replaceAll("_", "/");
  const padding = "=".repeat((4 - (normalized.length % 4)) % 4);
  const binary = atob(`${normalized}${padding}`);
  return Uint8Array.from(binary, (character) => character.charCodeAt(0)).buffer;
}

export async function createPasskeyCredential(
  options: SandIamWebAuthnOptions,
): Promise<Readonly<Record<string, unknown>>> {
  if (!window.isSecureContext || !("credentials" in navigator) || !("PublicKeyCredential" in window)) {
    throw new Error("此设备不支持通行密钥，请使用 HTTPS 和受支持的浏览器。");
  }
  if (options.user === undefined) throw new Error("通行密钥注册信息不完整。");
  const publicKey: PublicKeyCredentialCreationOptions = {
      challenge: fromBase64Url(options.challenge),
      rp: {
        name: options.rp?.name ?? "当前应用",
        ...(options.rp?.id === undefined ? {} : { id: options.rp.id }),
      },
      user: {
        id: fromBase64Url(options.user.id),
        name: options.user.name,
        displayName: options.user.displayName,
      },
      pubKeyCredParams: [...(options.pubKeyCredParams ?? [{ type: "public-key", alg: -7 }])],
      ...(options.authenticatorSelection === undefined ? {} : { authenticatorSelection: options.authenticatorSelection }),
      ...(options.attestation === undefined ? {} : { attestation: options.attestation }),
      ...(options.timeout === undefined ? {} : { timeout: options.timeout }),
    };
  const credential = await navigator.credentials.create({ publicKey });
  if (!(credential instanceof PublicKeyCredential)) throw new Error("未取得通行密钥凭据。");
  const response = credential.response;
  if (!(response instanceof AuthenticatorAttestationResponse)) throw new Error("通行密钥响应不完整。");
  return {
    id: credential.id,
    rawId: base64Url(credential.rawId),
    type: credential.type,
    response: {
      clientDataJSON: base64Url(response.clientDataJSON),
      attestationObject: base64Url(response.attestationObject),
    },
  };
}

export async function getPasskeyAssertion(
  options: SandIamWebAuthnAssertionOptions,
): Promise<Readonly<Record<string, unknown>>> {
  if (!window.isSecureContext || !("credentials" in navigator) || !("PublicKeyCredential" in window)) {
    throw new Error("此设备不支持通行密钥，请使用 HTTPS 和受支持的浏览器。");
  }
  const publicKey: PublicKeyCredentialRequestOptions = {
    challenge: fromBase64Url(options.challenge),
    rpId: options.rpId,
    allowCredentials: [...options.allowCredentials],
    userVerification: options.userVerification,
    ...(options.timeout === undefined ? {} : { timeout: options.timeout }),
  };
  const credential = await navigator.credentials.get({ publicKey });
  if (!(credential instanceof PublicKeyCredential) || !(credential.response instanceof AuthenticatorAssertionResponse)) {
    throw new Error("未取得通行密钥响应。");
  }
  return {
    id: credential.id,
    rawId: base64Url(credential.rawId),
    type: credential.type,
    response: {
      clientDataJSON: base64Url(credential.response.clientDataJSON),
      authenticatorData: base64Url(credential.response.authenticatorData),
      signature: base64Url(credential.response.signature),
      userHandle: credential.response.userHandle === null ? "" : base64Url(credential.response.userHandle),
    },
  };
}
