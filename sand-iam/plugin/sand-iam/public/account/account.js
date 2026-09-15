// sand-iam/portal/src/experienceContracts.ts
var REGISTRATION_FIELD_LABELS = {
  username: "\u7528\u6237\u540D",
  display_name: "\u663E\u793A\u540D\u79F0",
  email: "\u90AE\u7BB1",
  phone: "\u624B\u673A\u53F7"
};
function isRecord(value) {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}
function unwrap(value) {
  return isRecord(value) && "data" in value ? value.data : value;
}
function readString(value) {
  return typeof value === "string" ? value : "";
}
function readStringList(value) {
  return Array.isArray(value) ? value.filter((item) => typeof item === "string" && item !== "") : [];
}
function readRegistrationFields(value) {
  return readStringList(value).filter(
    (field) => field === "username" || field === "display_name" || field === "email" || field === "phone"
  );
}
function registrationFieldLabel(field) {
  return REGISTRATION_FIELD_LABELS[field];
}
function parsePublicExperience(value) {
  const payload = unwrap(value);
  if (!isRecord(payload)) return null;
  const organizationCode = readString(payload.organization_code).trim();
  const applicationCode = readString(payload.application_code).trim();
  const brandName = readString(payload.brand_name).trim();
  const registrationMode = payload.registration_mode;
  if (organizationCode === "" || applicationCode === "" || brandName === "" || registrationMode !== "open" && registrationMode !== "invite" && registrationMode !== "disabled") {
    return null;
  }
  return {
    organizationCode,
    applicationCode,
    brandName,
    logoUrl: readString(payload.logo_url),
    primaryColor: readString(payload.primary_color) || "#1677ff",
    themeMode: readString(payload.theme_mode) || "system",
    defaultLocale: readString(payload.default_locale) || "zh-CN",
    termsUrl: readString(payload.terms_url),
    privacyUrl: readString(payload.privacy_url),
    registrationMode,
    loginMethods: readStringList(payload.login_methods),
    registrationFields: readRegistrationFields(payload.registration_fields)
  };
}
function experienceAllowsPassword(experience) {
  return experience.loginMethods.includes("password");
}
function experienceAllowsRegister(experience) {
  return experience.registrationMode === "open";
}
function defaultPasswordExperience(organizationCode, applicationCode) {
  return {
    organizationCode,
    applicationCode,
    brandName: "\u5F53\u524D\u5E94\u7528",
    logoUrl: "",
    primaryColor: "#1677ff",
    themeMode: "system",
    defaultLocale: "zh-CN",
    termsUrl: "",
    privacyUrl: "",
    registrationMode: "disabled",
    loginMethods: ["password"],
    registrationFields: ["username", "display_name", "email"]
  };
}
function experienceAllowsPasskey(experience) {
  return experience.loginMethods.includes("passkey");
}
function externalLoginMethods(experience) {
  const methods = [];
  for (const method of experience.loginMethods) {
    const match = /^(oidc|oauth2|saml):([A-Za-z0-9_-]{20,128})$/u.exec(method);
    if (match === null) continue;
    const protocol = match[1];
    if (protocol !== "oidc" && protocol !== "oauth2" && protocol !== "saml") continue;
    methods.push({ protocol, providerCode: match[2] });
  }
  return methods;
}
function parsePortalAuthResult(value) {
  const payload = unwrap(value);
  if (!isRecord(payload)) return null;
  if (payload.verification_required === true) {
    return {
      accessToken: "",
      verificationRequired: true,
      mfaRequired: false,
      displayName: isRecord(payload.identity) && typeof payload.identity.display_name === "string" ? payload.identity.display_name : null
    };
  }
  if (payload.mfa_required === true) {
    const challengeToken = readString(payload.challenge_token).trim();
    const methods = readStringList(payload.methods).filter(
      (method) => method === "totp" || method === "recovery_code" || method === "passkey"
    );
    if (!/^siam_mc_[a-f0-9]{64}$/.test(challengeToken) || methods.length === 0) return null;
    return {
      accessToken: "",
      verificationRequired: false,
      mfaRequired: true,
      challengeToken,
      methods,
      passkeyOptions: isRecord(payload.public_key) ? payload.public_key : null,
      displayName: isRecord(payload.identity) && typeof payload.identity.display_name === "string" ? payload.identity.display_name : null
    };
  }
  const accessToken = readString(payload.access_token).trim();
  if (accessToken === "") return null;
  return {
    accessToken,
    verificationRequired: false,
    mfaRequired: false,
    displayName: isRecord(payload.identity) && typeof payload.identity.display_name === "string" ? payload.identity.display_name : null
  };
}

// sand-iam/portal/src/meContracts.ts
function isRecord2(value) {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}
function readString2(value) {
  return typeof value === "string" && value.trim() !== "" ? value : null;
}
function unwrap2(value) {
  return isRecord2(value) && "data" in value ? value.data : value;
}
function parsePortalProfile(value) {
  const payload = unwrap2(value);
  if (!isRecord2(payload)) return null;
  const displayName = readString2(payload.display_name);
  const organization = isRecord2(payload.organization) ? payload.organization : null;
  const application = isRecord2(payload.application) ? payload.application : null;
  const organizationName = organization === null ? null : readString2(organization.name);
  const organizationCode = organization === null ? null : readString2(organization.code);
  const applicationName = application === null ? null : readString2(application.name);
  const applicationCode = application === null ? null : readString2(application.code);
  if (displayName === null || organizationName === null || organizationCode === null || applicationName === null || applicationCode === null) {
    return null;
  }
  return {
    displayName,
    organizationName,
    organizationCode,
    applicationName,
    applicationCode,
    createTime: readString2(payload.create_time)
  };
}
function parsePortalSecurity(value) {
  const payload = unwrap2(value);
  if (!isRecord2(payload)) return null;
  const passwordEnabled = payload.password_enabled;
  const activeSessions = payload.active_sessions;
  const totpFactors = payload.totp_factors;
  const passkeys = payload.passkeys;
  const connectedAccounts = payload.connected_accounts;
  if (typeof passwordEnabled !== "boolean" || typeof activeSessions !== "number" || typeof totpFactors !== "number" || typeof passkeys !== "number" || typeof connectedAccounts !== "number") {
    return null;
  }
  return {
    passwordEnabled,
    activeSessions,
    totpFactors,
    passkeys,
    connectedAccounts
  };
}
function parsePortalConnection(value) {
  if (!isRecord2(value)) return null;
  const bindingId = value.binding_id;
  const providerName = readString2(value.provider_name);
  const providerType = readString2(value.provider_type);
  const accountHint = readString2(value.account_hint);
  const sourceState = readString2(value.source_state);
  if (typeof bindingId !== "number" || !Number.isInteger(bindingId) || bindingId <= 0 || providerName === null || providerType === null || accountHint === null || sourceState === null) {
    return null;
  }
  return {
    bindingId,
    providerName,
    providerType,
    accountHint,
    sourceState,
    linkedTime: readString2(value.linked_time)
  };
}
function parsePortalConnections(value) {
  const payload = unwrap2(value);
  if (!Array.isArray(payload)) {
    throw new Error("\u5916\u90E8\u8FDE\u63A5\u8FD4\u56DE\u683C\u5F0F\u4E0D\u7B26\u5408\u5DF2\u51BB\u7ED3\u7EA6\u5B9A");
  }
  return payload.map((item) => parsePortalConnection(item)).filter((item) => item !== null);
}
function connectionAvailable(state2) {
  return state2 === "active";
}
function parsePortalSession(value) {
  if (!isRecord2(value)) return null;
  const id = value.id;
  const createTime = readString2(value.create_time);
  const lastUsedTime = readString2(value.last_used_time);
  const accessExpireTime = readString2(value.access_expire_time);
  const refreshExpireTime = readString2(value.refresh_expire_time);
  if (typeof id !== "number" || !Number.isInteger(id) || id <= 0 || createTime === null || lastUsedTime === null || accessExpireTime === null || refreshExpireTime === null) {
    return null;
  }
  return {
    id,
    createTime,
    lastUsedTime,
    accessExpireTime,
    refreshExpireTime,
    current: value.current === true || value.current === 1
  };
}
function parsePortalSessions(value) {
  const payload = unwrap2(value);
  if (!Array.isArray(payload)) {
    throw new Error("\u4F1A\u8BDD\u5217\u8868\u8FD4\u56DE\u683C\u5F0F\u4E0D\u7B26\u5408\u5DF2\u51BB\u7ED3\u7EA6\u5B9A");
  }
  return payload.map((item) => parsePortalSession(item)).filter((item) => item !== null);
}
function parsePortalFactor(value) {
  if (!isRecord2(value)) return null;
  const id = value.id;
  const type = value.type;
  const name = readString2(value.name);
  const status = value.status;
  const createTime = readString2(value.create_time);
  if (typeof id !== "number" || !Number.isInteger(id) || id <= 0 || type !== "totp" && type !== "passkey" || name === null || typeof status !== "number" || createTime === null) {
    return null;
  }
  return {
    id,
    type,
    name,
    status,
    createTime,
    lastUsedTime: readString2(value.last_used_time)
  };
}
function parsePortalFactors(value) {
  const payload = unwrap2(value);
  if (!Array.isArray(payload)) {
    throw new Error("\u8BA4\u8BC1\u65B9\u5F0F\u5217\u8868\u8FD4\u56DE\u683C\u5F0F\u4E0D\u7B26\u5408\u5DF2\u51BB\u7ED3\u7EA6\u5B9A");
  }
  return payload.map((item) => parsePortalFactor(item)).filter((item) => item !== null);
}
function portalRequestHeaders(accessToken, requestId, hasBody) {
  const headers = {
    Accept: "application/json",
    Authorization: `Bearer ${accessToken}`,
    "X-Request-Id": requestId
  };
  if (hasBody) headers["Content-Type"] = "application/json";
  return headers;
}

// sand-iam/portal/src/captchaWidget.ts
function parseCaptchaConfiguration(value) {
  if (typeof value !== "object" || value === null) return null;
  if (!("kind" in value) || value.kind !== "turnstile" || !("site_key" in value) || typeof value.site_key !== "string" || !/^[a-zA-Z0-9_-]{1,255}$/.test(value.site_key) || !("action" in value) || value.action !== "login" && value.action !== "register" || !("application_binding" in value) || typeof value.application_binding !== "string" || !/^[a-zA-Z0-9_-]{1,255}$/.test(value.application_binding)) return null;
  return {
    kind: value.kind,
    site_key: value.site_key,
    action: value.action,
    application_binding: value.application_binding
  };
}
function readSdk() {
  const value = Reflect.get(globalThis, "turnstile");
  if (typeof value !== "object" || value === null || !("ready" in value) || typeof value.ready !== "function" || !("render" in value) || typeof value.render !== "function" || !("remove" in value) || typeof value.remove !== "function") return null;
  const { ready, render: render2, remove } = value;
  return {
    ready: (callback) => {
      ready.call(value, callback);
    },
    render: (container, options) => render2.call(value, container, options),
    remove: (id) => {
      remove.call(value, id);
    }
  };
}
var SCRIPT_URL = "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";
var pendingSdk = null;
function loadSdk() {
  if (pendingSdk !== null) return pendingSdk;
  const attempt = new Promise((resolve, reject) => {
    let script = null;
    let settled = false;
    const finish = (sdk) => {
      if (settled) return;
      settled = true;
      clearTimeout(timer);
      if (script !== null) {
        script.onload = null;
        script.onerror = null;
      }
      if (sdk !== null) resolve(sdk);
      else {
        script?.remove();
        reject(new Error("\u4EBA\u673A\u9A8C\u8BC1\u52A0\u8F7D\u5931\u8D25\uFF0C\u8BF7\u91CD\u8BD5\u3002"));
      }
    };
    const timer = setTimeout(() => finish(null), 15e3);
    const ready = () => {
      const sdk = readSdk();
      if (sdk === null) {
        finish(null);
        return;
      }
      try {
        sdk.ready(() => finish(sdk));
      } catch {
        finish(null);
      }
    };
    if (readSdk() !== null) {
      ready();
      return;
    }
    script = document.createElement("script");
    script.src = SCRIPT_URL;
    script.async = true;
    script.defer = true;
    script.onload = ready;
    script.onerror = () => finish(null);
    try {
      document.head.append(script);
    } catch {
      finish(null);
    }
  });
  pendingSdk = attempt;
  void attempt.catch(() => {
    if (pendingSdk === attempt) pendingSdk = null;
  });
  return attempt;
}
var CaptchaWidget = class {
  generation = 0;
  token = "";
  validUntil = 0;
  sdk = null;
  widgetId = null;
  status = "destroyed";
  container;
  onChange;
  constructor(container, onChange) {
    this.container = container;
    this.onChange = onChange;
  }
  release() {
    this.generation += 1;
    this.token = "";
    this.validUntil = 0;
    const id = this.widgetId;
    const sdk = this.sdk;
    this.widgetId = null;
    this.sdk = null;
    if (id !== null && sdk !== null) {
      try {
        sdk.remove(id);
      } catch {
        return false;
      }
    }
    return true;
  }
  notify(status) {
    this.status = status;
    this.onChange(status);
  }
  async mount(value) {
    if (!this.release()) {
      this.notify("error");
      return;
    }
    const configuration = parseCaptchaConfiguration(value);
    if (configuration === null || !this.container.isConnected) {
      this.notify("error");
      return;
    }
    const generation = this.generation;
    const current = () => generation === this.generation && this.container.isConnected;
    const fail = (status) => {
      if (!current()) return;
      const removed = this.release();
      this.notify(removed ? status : "error");
    };
    this.notify("loading");
    if (!current()) return;
    try {
      const sdk = await loadSdk();
      if (!current()) return;
      this.sdk = sdk;
      this.notify("waiting");
      if (!current()) return;
      const id = sdk.render(this.container, {
        sitekey: configuration.site_key,
        action: configuration.action,
        cData: configuration.application_binding,
        "response-field": false,
        retry: "never",
        "refresh-expired": "manual",
        "refresh-timeout": "manual",
        callback: (token) => {
          if (!current()) return;
          if (typeof token !== "string" || token === "" || token.length > 2048) {
            fail("error");
            return;
          }
          this.token = token;
          this.validUntil = Date.now() + 3e5;
          this.notify("verified");
        },
        "expired-callback": () => fail("expired"),
        "error-callback": () => fail("error"),
        "timeout-callback": () => fail("expired"),
        "unsupported-callback": () => fail("error")
      });
      if (typeof id !== "string" || id === "") {
        fail("error");
        return;
      }
      if (!current()) {
        sdk.remove(id);
        return;
      }
      this.widgetId = id;
    } catch {
      fail("error");
    }
  }
  /** Returns a token once. A new mount is required for any subsequent submission. */
  takeToken() {
    const token = this.status === "verified" && this.container.isConnected && Date.now() < this.validUntil ? this.token : "";
    this.destroy();
    return token;
  }
  destroy() {
    this.notify(this.release() ? "destroyed" : "error");
  }
};

// sand-iam/portal/src/casContracts.ts
function isRecord3(value) {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}
function unwrap3(value) {
  return isRecord3(value) && "data" in value ? value.data : value;
}
function parseCasInteraction(value) {
  const payload = unwrap3(value);
  if (!isRecord3(payload)) return null;
  const applicationName = payload.application_name;
  const organizationCode = payload.organization_code;
  const applicationCode = payload.application_code;
  const serviceName = payload.service_name;
  const serviceUrl = payload.service_url;
  const expiresIn = payload.expires_in;
  if (typeof applicationName !== "string" || applicationName.trim() === "" || typeof organizationCode !== "string" || organizationCode.trim() === "" || typeof applicationCode !== "string" || applicationCode.trim() === "" || typeof serviceName !== "string" || serviceName.trim() === "" || typeof serviceUrl !== "string" || serviceUrl.trim() === "" || typeof expiresIn !== "number") {
    return null;
  }
  return {
    organizationCode: organizationCode.trim(),
    applicationCode: applicationCode.trim(),
    applicationName,
    serviceName,
    serviceUrl,
    expiresIn
  };
}
function parseCasConfirm(value) {
  const payload = unwrap3(value);
  if (!isRecord3(payload) || typeof payload.redirect_uri !== "string" || payload.redirect_uri === "") {
    return null;
  }
  return {
    redirectUri: payload.redirect_uri,
    expiresIn: typeof payload.expires_in === "number" ? payload.expires_in : 0
  };
}
function casRequestLooksValid(request) {
  return /^CRT-[A-Za-z0-9_-]{48}$/.test(request);
}

// sand-iam/portal/src/oauthContracts.ts
function isRecord4(value) {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}
function unwrap4(value) {
  return isRecord4(value) && "data" in value ? value.data : value;
}
function readStringList2(value) {
  return Array.isArray(value) ? value.filter((item) => typeof item === "string" && item.trim() !== "") : [];
}
function oauthRequestLooksValid(request) {
  return /^siam_oar_[a-f0-9]{64}$/.test(request);
}
function parseOAuthInteraction(value) {
  const payload = unwrap4(value);
  if (!isRecord4(payload)) return null;
  const clientName = payload.client_name;
  const clientId = payload.client_id;
  const organizationCode = payload.organization_code;
  const applicationCode = payload.application_code;
  const expiresIn = payload.expires_in;
  if (typeof clientName !== "string" || clientName.trim() === "" || typeof clientId !== "string" || clientId.trim() === "" || typeof organizationCode !== "string" || organizationCode.trim() === "" || typeof applicationCode !== "string" || applicationCode.trim() === "" || typeof expiresIn !== "number" || !Number.isFinite(expiresIn)) {
    return null;
  }
  return {
    clientName: clientName.trim(),
    clientId: clientId.trim(),
    organizationCode: organizationCode.trim(),
    applicationCode: applicationCode.trim(),
    scopes: readStringList2(payload.scope),
    expiresIn,
    bound: payload.bound === true
  };
}
function parseOAuthBoundInteraction(value) {
  const payload = unwrap4(value);
  if (!isRecord4(payload)) return null;
  const csrfToken = payload.csrf_token;
  const clientName = payload.client_name;
  const consentRequired = payload.consent_required;
  const expiresIn = payload.expires_in;
  if (typeof csrfToken !== "string" || !/^siam_oac_[a-f0-9]{64}$/.test(csrfToken) || typeof clientName !== "string" || clientName.trim() === "" || typeof consentRequired !== "boolean" || typeof expiresIn !== "number" || !Number.isFinite(expiresIn)) {
    return null;
  }
  return {
    csrfToken,
    clientName: clientName.trim(),
    scopes: readStringList2(payload.scope),
    consentRequired,
    expiresIn
  };
}
function parseOAuthDecision(value) {
  const payload = unwrap4(value);
  if (!isRecord4(payload) || typeof payload.redirect_uri !== "string") return null;
  const redirectUri = payload.redirect_uri.trim();
  return redirectUri === "" ? null : { redirectUri };
}

// sand-iam/portal/src/mfaContracts.ts
function isRecord5(value) {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}
function readString3(value) {
  return typeof value === "string" && value.trim() !== "" ? value : null;
}
function parseTotpStart(value) {
  const payload = isRecord5(value) && "data" in value ? value.data : value;
  if (!isRecord5(payload)) return null;
  const factorId = payload.factor_id;
  const secret = readString3(payload.secret);
  const otpAuthUri = readString3(payload.otpauth_uri);
  if (!Number.isInteger(factorId) || typeof factorId !== "number" || factorId <= 0 || secret === null || otpAuthUri === null) {
    return null;
  }
  return { factorId, secret, otpAuthUri };
}
function parseRecoveryCodes(value) {
  const payload = isRecord5(value) && "data" in value ? value.data : value;
  if (!isRecord5(payload) || !Array.isArray(payload.recovery_codes)) return null;
  const codes = payload.recovery_codes.filter(
    (item) => typeof item === "string" && item.trim() !== ""
  );
  return codes.length === payload.recovery_codes.length ? codes : null;
}
function parsePasskeyOptions(value) {
  const payload = isRecord5(value) && "data" in value ? value.data : value;
  if (!isRecord5(payload) || !isRecord5(payload.public_key)) return null;
  const options = payload.public_key;
  const challenge = readString3(options.challenge);
  const user = isRecord5(options.user) ? options.user : null;
  const userId = user === null ? null : readString3(user.id);
  const userName = user === null ? null : readString3(user.name);
  const displayName = user === null ? null : readString3(user.displayName);
  if (challenge === null || userId === null || userName === null || displayName === null) return null;
  const rpId = isRecord5(options.rp) ? readString3(options.rp.id) : null;
  const rpName = isRecord5(options.rp) ? readString3(options.rp.name) : null;
  const rp = rpId === null && rpName === null ? void 0 : {
    ...rpId === null ? {} : { id: rpId },
    ...rpName === null ? {} : { name: rpName }
  };
  const residentKey = isRecord5(options.authenticatorSelection) && options.authenticatorSelection.residentKey === "required" ? "required" : void 0;
  const userVerification = isRecord5(options.authenticatorSelection) && (options.authenticatorSelection.userVerification === "required" || options.authenticatorSelection.userVerification === "discouraged") ? options.authenticatorSelection.userVerification : "preferred";
  const authenticatorSelection = isRecord5(options.authenticatorSelection) ? {
    ...residentKey === void 0 ? {} : { residentKey },
    userVerification
  } : void 0;
  return {
    challenge,
    user: { id: userId, name: userName, displayName },
    ...rp === void 0 ? {} : { rp },
    ...Array.isArray(options.pubKeyCredParams) ? {
      pubKeyCredParams: options.pubKeyCredParams.filter(isRecord5).map((item) => ({
        type: item.type === "public-key" ? "public-key" : "public-key",
        alg: typeof item.alg === "number" ? item.alg : -7
      }))
    } : {},
    ...authenticatorSelection === void 0 ? {} : { authenticatorSelection },
    ...options.attestation === "direct" || options.attestation === "enterprise" || options.attestation === "none" ? { attestation: options.attestation } : {},
    ...typeof options.timeout === "number" && Number.isFinite(options.timeout) ? { timeout: options.timeout } : {}
  };
}
function parsePasskeyAssertionOptions(value) {
  if (!isRecord5(value)) return null;
  const challenge = readString3(value.challenge);
  const rpId = readString3(value.rpId);
  if (challenge === null || rpId === null) return null;
  const userVerification = value.userVerification === "required" || value.userVerification === "discouraged" ? value.userVerification : "preferred";
  const allowCredentials = Array.isArray(value.allowCredentials) ? value.allowCredentials.flatMap((item) => {
    if (!isRecord5(item) || item.type !== "public-key") return [];
    const id = readString3(item.id);
    return id === null ? [] : [{ type: "public-key", id: fromBase64Url(id) }];
  }) : [];
  return {
    challenge,
    rpId,
    allowCredentials,
    userVerification,
    ...typeof value.timeout === "number" && Number.isFinite(value.timeout) ? { timeout: value.timeout } : {}
  };
}
function base64Url(bytes) {
  const binary = String.fromCharCode(...new Uint8Array(bytes));
  return btoa(binary).replaceAll("+", "-").replaceAll("/", "_").replace(/=+$/u, "");
}
function fromBase64Url(value) {
  const normalized = value.replaceAll("-", "+").replaceAll("_", "/");
  const padding = "=".repeat((4 - normalized.length % 4) % 4);
  const binary = atob(`${normalized}${padding}`);
  return Uint8Array.from(binary, (character) => character.charCodeAt(0)).buffer;
}
async function createPasskeyCredential(options) {
  if (!window.isSecureContext || !("credentials" in navigator) || !("PublicKeyCredential" in window)) {
    throw new Error("\u6B64\u8BBE\u5907\u4E0D\u652F\u6301\u901A\u884C\u5BC6\u94A5\uFF0C\u8BF7\u4F7F\u7528 HTTPS \u548C\u53D7\u652F\u6301\u7684\u6D4F\u89C8\u5668\u3002");
  }
  if (options.user === void 0) throw new Error("\u901A\u884C\u5BC6\u94A5\u6CE8\u518C\u4FE1\u606F\u4E0D\u5B8C\u6574\u3002");
  const publicKey = {
    challenge: fromBase64Url(options.challenge),
    rp: {
      name: options.rp?.name ?? "\u5F53\u524D\u5E94\u7528",
      ...options.rp?.id === void 0 ? {} : { id: options.rp.id }
    },
    user: {
      id: fromBase64Url(options.user.id),
      name: options.user.name,
      displayName: options.user.displayName
    },
    pubKeyCredParams: [...options.pubKeyCredParams ?? [{ type: "public-key", alg: -7 }]],
    ...options.authenticatorSelection === void 0 ? {} : { authenticatorSelection: options.authenticatorSelection },
    ...options.attestation === void 0 ? {} : { attestation: options.attestation },
    ...options.timeout === void 0 ? {} : { timeout: options.timeout }
  };
  const credential = await navigator.credentials.create({ publicKey });
  if (!(credential instanceof PublicKeyCredential)) throw new Error("\u672A\u53D6\u5F97\u901A\u884C\u5BC6\u94A5\u51ED\u636E\u3002");
  const response = credential.response;
  if (!(response instanceof AuthenticatorAttestationResponse)) throw new Error("\u901A\u884C\u5BC6\u94A5\u54CD\u5E94\u4E0D\u5B8C\u6574\u3002");
  return {
    id: credential.id,
    rawId: base64Url(credential.rawId),
    type: credential.type,
    response: {
      clientDataJSON: base64Url(response.clientDataJSON),
      attestationObject: base64Url(response.attestationObject)
    }
  };
}
async function getPasskeyAssertion(options) {
  if (!window.isSecureContext || !("credentials" in navigator) || !("PublicKeyCredential" in window)) {
    throw new Error("\u6B64\u8BBE\u5907\u4E0D\u652F\u6301\u901A\u884C\u5BC6\u94A5\uFF0C\u8BF7\u4F7F\u7528 HTTPS \u548C\u53D7\u652F\u6301\u7684\u6D4F\u89C8\u5668\u3002");
  }
  const publicKey = {
    challenge: fromBase64Url(options.challenge),
    rpId: options.rpId,
    allowCredentials: [...options.allowCredentials],
    userVerification: options.userVerification,
    ...options.timeout === void 0 ? {} : { timeout: options.timeout }
  };
  const credential = await navigator.credentials.get({ publicKey });
  if (!(credential instanceof PublicKeyCredential) || !(credential.response instanceof AuthenticatorAssertionResponse)) {
    throw new Error("\u672A\u53D6\u5F97\u901A\u884C\u5BC6\u94A5\u54CD\u5E94\u3002");
  }
  return {
    id: credential.id,
    rawId: base64Url(credential.rawId),
    type: credential.type,
    response: {
      clientDataJSON: base64Url(credential.response.clientDataJSON),
      authenticatorData: base64Url(credential.response.authenticatorData),
      signature: base64Url(credential.response.signature),
      userHandle: credential.response.userHandle === null ? "" : base64Url(credential.response.userHandle)
    }
  };
}

// sand-iam/portal/src/invitationContracts.ts
function isRecord6(value) {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}
function invitationTokenLooksValid(token) {
  return /^siam_inv_[A-Za-z0-9_-]{43}$/.test(token);
}
function parseInvitationAcceptResult(value) {
  const payload = isRecord6(value) && "data" in value ? value.data : value;
  if (!isRecord6(payload)) return null;
  const displayName = payload.display_name;
  if (typeof displayName !== "string" || displayName.trim() === "") return null;
  return { displayName: displayName.trim() };
}

// sand-iam/portal/src/runtime.ts
var SAND_IAM_PORTAL_AUTH_PREFIX = "/api/sand-iam/v1/auth";
var SAND_IAM_PORTAL_ME_PREFIX = "/api/sand-iam/v1/me";
var SAND_IAM_PORTAL_EXPERIENCE = "/api/sand-iam/v1/experience";
var SAND_IAM_PORTAL_INVITATION_ACCEPT = "/api/sand-iam/v1/invitations/accept";
var SAND_IAM_PORTAL_CAS_INTERACTION = "/api/sand-iam/v1/cas/interaction";
var SAND_IAM_PORTAL_CAS_CONFIRM = "/api/sand-iam/v1/cas/interaction/confirm";
var SAND_IAM_PORTAL_CAS_REJECT = "/api/sand-iam/v1/cas/interaction/reject";
var SAND_IAM_PORTAL_OAUTH_INTERACTION = "/api/sand-iam/v1/oauth/interaction";
var SAND_IAM_PORTAL_OAUTH_BIND = "/api/sand-iam/v1/oauth/interaction/session";
var SAND_IAM_PORTAL_OAUTH_CONFIRM = "/api/sand-iam/v1/oauth/interaction/confirm";
var SAND_IAM_PORTAL_FEDERATION_PREFIX = "/api/sand-iam/v1/federation";
var SandIamPortalTransportError = class extends Error {
  http;
  constructor(message, http) {
    super(message);
    this.name = "SandIamPortalTransportError";
    this.http = http;
  }
};
function isRecord7(value) {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}
function readMessage(body) {
  if (body === null) return "";
  const message = body.msg ?? body.message ?? body.code;
  return typeof message === "string" ? message : "";
}
function createRequestId() {
  if (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function") {
    return crypto.randomUUID();
  }
  return `sand-iam-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
}
async function portalRequest(method, url, accessToken, body) {
  const token = accessToken.trim();
  if (token === "") {
    throw new SandIamPortalTransportError("\u8BF7\u5148\u767B\u5F55\u5F53\u524D\u5E94\u7528\u8D26\u53F7\u3002", 401);
  }
  const requestId = createRequestId();
  let response;
  try {
    const init = {
      method,
      credentials: "omit",
      headers: portalRequestHeaders(token, requestId, body !== void 0)
    };
    if (body !== void 0) init.body = JSON.stringify(body);
    response = await fetch(url, init);
  } catch {
    throw new SandIamPortalTransportError(
      "\u7F51\u7EDC\u4E0D\u53EF\u7528\uFF0C\u8BF7\u68C0\u67E5\u8FDE\u63A5\u540E\u91CD\u8BD5\uFF1B\u6301\u7EED\u5931\u8D25\u65F6\u8054\u7CFB\u5E94\u7528\u7BA1\u7406\u5458\u3002",
      null
    );
  }
  let parsed = null;
  try {
    parsed = await response.json();
  } catch {
    parsed = null;
  }
  const record = isRecord7(parsed) ? parsed : null;
  if (!response.ok) {
    throw new SandIamPortalTransportError(
      readMessage(record) || `\u8BF7\u6C42\u672A\u5B8C\u6210\uFF08HTTP ${String(response.status)}\uFF09`,
      response.status
    );
  }
  return { requestId, data: record !== null && "data" in record ? record.data : parsed };
}
async function loadPortalCaptchaConfiguration(organizationCode, applicationCode, action) {
  const requestId = createRequestId();
  const query = new URLSearchParams({
    organization_code: organizationCode,
    application_code: applicationCode,
    action
  });
  let response;
  try {
    response = await fetch(`${SAND_IAM_PORTAL_AUTH_PREFIX}/captcha/config?${query.toString()}`, {
      method: "GET",
      credentials: "omit",
      cache: "no-store",
      headers: { Accept: "application/json", "X-Request-Id": requestId }
    });
  } catch {
    throw new SandIamPortalTransportError("\u4EBA\u673A\u9A8C\u8BC1\u914D\u7F6E\u52A0\u8F7D\u5931\u8D25\uFF0C\u8BF7\u68C0\u67E5\u8FDE\u63A5\u540E\u91CD\u8BD5\u3002", null);
  }
  let parsed = null;
  try {
    parsed = await response.json();
  } catch {
    parsed = null;
  }
  const record = isRecord7(parsed) ? parsed : null;
  if (!response.ok) {
    throw new SandIamPortalTransportError(
      readMessage(record) || `\u4EBA\u673A\u9A8C\u8BC1\u914D\u7F6E\u4E0D\u53EF\u7528\uFF08HTTP ${String(response.status)}\uFF09`,
      response.status
    );
  }
  const payload = record !== null && "data" in record ? record.data : parsed;
  if (isRecord7(payload)) {
    if (payload.required === false && payload.available === void 0 && payload.widget === void 0) {
      return { requestId, data: { required: false } };
    }
    if (payload.required === true && payload.available === false && payload.widget === void 0) {
      return { requestId, data: { required: true, available: false } };
    }
    if (payload.required === true && payload.available === true) {
      const widget = parseCaptchaConfiguration(payload.widget);
      if (widget !== null && widget.action === action) {
        return { requestId, data: { required: true, available: true, widget } };
      }
    }
  }
  throw new SandIamPortalTransportError("\u4EBA\u673A\u9A8C\u8BC1\u914D\u7F6E\u8FD4\u56DE\u683C\u5F0F\u4E0D\u7B26\u5408\u5DF2\u51BB\u7ED3\u7EA6\u5B9A", null);
}
async function loadPublicExperience(organizationCode, applicationCode) {
  const requestId = createRequestId();
  let response;
  try {
    const query = new URLSearchParams({
      organization_code: organizationCode,
      application_code: applicationCode
    });
    response = await fetch(`${SAND_IAM_PORTAL_EXPERIENCE}?${query.toString()}`, {
      method: "GET",
      credentials: "omit",
      headers: {
        Accept: "application/json",
        "X-Request-Id": requestId
      }
    });
  } catch {
    throw new SandIamPortalTransportError("\u7F51\u7EDC\u4E0D\u53EF\u7528\uFF0C\u8BF7\u68C0\u67E5\u8FDE\u63A5\u540E\u91CD\u8BD5\u3002", null);
  }
  let parsed = null;
  try {
    parsed = await response.json();
  } catch {
    parsed = null;
  }
  if (!response.ok) {
    const record = isRecord7(parsed) ? parsed : null;
    throw new SandIamPortalTransportError(
      readMessage(record) || `\u8BF7\u6C42\u672A\u5B8C\u6210\uFF08HTTP ${String(response.status)}\uFF09`,
      response.status
    );
  }
  const experience = parsePublicExperience(parsed);
  if (experience === null) {
    throw new SandIamPortalTransportError("\u767B\u5F55\u5916\u89C2\u8FD4\u56DE\u683C\u5F0F\u4E0D\u7B26\u5408\u5DF2\u51BB\u7ED3\u7EA6\u5B9A", null);
  }
  return { requestId, data: experience };
}
async function portalPublicAuth(path, body) {
  const requestId = createRequestId();
  let response;
  try {
    response = await fetch(`${SAND_IAM_PORTAL_AUTH_PREFIX}${path}`, {
      method: "POST",
      credentials: "omit",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        "X-Request-Id": requestId
      },
      body: JSON.stringify(body)
    });
  } catch {
    throw new SandIamPortalTransportError("\u7F51\u7EDC\u4E0D\u53EF\u7528\uFF0C\u8BF7\u68C0\u67E5\u8FDE\u63A5\u540E\u91CD\u8BD5\u3002", null);
  }
  let parsed = null;
  try {
    parsed = await response.json();
  } catch {
    parsed = null;
  }
  const record = isRecord7(parsed) ? parsed : null;
  if (!response.ok) {
    throw new SandIamPortalTransportError(
      readMessage(record) || `\u8BF7\u6C42\u672A\u5B8C\u6210\uFF08HTTP ${String(response.status)}\uFF09`,
      response.status
    );
  }
  return { requestId, data: record !== null && "data" in record ? record.data : parsed };
}
async function portalLogin(organizationCode, applicationCode, identifier, password, captchaToken) {
  const body = {
    organization_code: organizationCode,
    application_code: applicationCode,
    identifier,
    password
  };
  if (captchaToken.trim() !== "") body.captcha_token = captchaToken.trim();
  const result = await portalPublicAuth("/login", body);
  const tokens = parsePortalAuthResult(result.data);
  if (tokens === null) {
    throw new SandIamPortalTransportError("\u767B\u5F55\u54CD\u5E94\u4E0D\u7B26\u5408\u5DF2\u51BB\u7ED3\u7EA6\u5B9A", null);
  }
  return { requestId: result.requestId, data: tokens };
}
async function portalRegister(organizationCode, applicationCode, fields) {
  const result = await portalPublicAuth("/register", {
    organization_code: organizationCode,
    application_code: applicationCode,
    ...fields
  });
  const tokens = parsePortalAuthResult(result.data);
  if (tokens === null) {
    throw new SandIamPortalTransportError("\u6CE8\u518C\u54CD\u5E94\u4E0D\u7B26\u5408\u5DF2\u51BB\u7ED3\u7EA6\u5B9A", null);
  }
  return { requestId: result.requestId, data: tokens };
}
async function portalIdentityVerification(organizationCode, applicationCode, identifier, channel, code) {
  return portalPublicAuth(code === void 0 ? "/verification/request" : "/verification/confirm", {
    organization_code: organizationCode,
    application_code: applicationCode,
    identifier,
    channel,
    purpose: channel === "email" ? "email_verify" : "phone_verify",
    ...code === void 0 ? {} : { code }
  });
}
async function verifyPortalMfaChallenge(organizationCode, applicationCode, challengeToken, method, code) {
  const result = await portalPublicAuth("/mfa/challenge/verify", {
    organization_code: organizationCode,
    application_code: applicationCode,
    challenge_token: challengeToken,
    method,
    code
  });
  const tokens = parsePortalAuthResult(result.data);
  if (tokens === null || tokens.mfaRequired || tokens.verificationRequired || tokens.accessToken === "") {
    throw new SandIamPortalTransportError("\u591A\u91CD\u9A8C\u8BC1\u54CD\u5E94\u4E0D\u7B26\u5408\u5DF2\u51BB\u7ED3\u7EA6\u5B9A", null);
  }
  return { requestId: result.requestId, data: tokens };
}
async function verifyPortalPasskeyChallenge(organizationCode, applicationCode, challengeToken, optionsPayload, assertion) {
  const options = parsePasskeyAssertionOptions(optionsPayload);
  if (options === null) throw new SandIamPortalTransportError("\u901A\u884C\u5BC6\u94A5\u767B\u5F55\u4FE1\u606F\u4E0D\u5B8C\u6574\u3002", null);
  const result = await portalPublicAuth("/mfa/challenge/verify", {
    organization_code: organizationCode,
    application_code: applicationCode,
    challenge_token: challengeToken,
    method: "passkey",
    ...assertion
  });
  const tokens = parsePortalAuthResult(result.data);
  if (tokens === null || tokens.mfaRequired || tokens.verificationRequired || tokens.accessToken === "") {
    throw new SandIamPortalTransportError("\u901A\u884C\u5BC6\u94A5\u767B\u5F55\u54CD\u5E94\u4E0D\u7B26\u5408\u5DF2\u51BB\u7ED3\u7EA6\u5B9A", null);
  }
  return { requestId: result.requestId, data: tokens };
}
async function startPortalPasskeyLogin(organizationCode, applicationCode) {
  const result = await portalPublicAuth("/passkeys/authentication/options", {
    organization_code: organizationCode,
    application_code: applicationCode
  });
  const payload = isRecord7(result.data) ? result.data : null;
  const challengeToken = payload === null || typeof payload.challenge_token !== "string" ? "" : payload.challenge_token.trim();
  const publicKey = payload === null ? null : payload.public_key;
  if (challengeToken === "" || !isRecord7(publicKey)) {
    throw new SandIamPortalTransportError("\u901A\u884C\u5BC6\u94A5\u767B\u5F55\u4FE1\u606F\u4E0D\u5B8C\u6574\u3002", null);
  }
  return { requestId: result.requestId, data: { challengeToken, publicKey } };
}
async function finishPortalPasskeyLogin(organizationCode, applicationCode, challengeToken, assertion) {
  const result = await portalPublicAuth("/passkeys/authentication/finish", {
    organization_code: organizationCode,
    application_code: applicationCode,
    challenge_token: challengeToken,
    ...assertion
  });
  const tokens = parsePortalAuthResult(result.data);
  if (tokens === null) {
    throw new SandIamPortalTransportError("\u901A\u884C\u5BC6\u94A5\u767B\u5F55\u54CD\u5E94\u4E0D\u7B26\u5408\u5DF2\u51BB\u7ED3\u7EA6\u5B9A", null);
  }
  return { requestId: result.requestId, data: tokens };
}
function federationStartEndpoint(protocol) {
  return `${SAND_IAM_PORTAL_FEDERATION_PREFIX}/${protocol}/start`;
}
function readRedirectUri(value) {
  if (!isRecord7(value) || typeof value.redirect_uri !== "string") return null;
  const redirectUri = value.redirect_uri.trim();
  return redirectUri === "" ? null : redirectUri;
}
async function startPortalFederationLogin(protocol, providerCode, applicationCode, returnUri, state2, verifier) {
  const requestId = createRequestId();
  const query = new URLSearchParams({
    provider: providerCode,
    application: applicationCode,
    return_uri: returnUri,
    state: state2,
    code_challenge: verifier,
    purpose: "login"
  });
  let response;
  try {
    response = await fetch(`${federationStartEndpoint(protocol)}?${query.toString()}`, {
      method: "GET",
      credentials: "omit",
      headers: { Accept: "application/json", "X-Request-Id": requestId }
    });
  } catch {
    throw new SandIamPortalTransportError("\u7F51\u7EDC\u4E0D\u53EF\u7528\uFF0C\u8BF7\u68C0\u67E5\u8FDE\u63A5\u540E\u91CD\u8BD5\u3002", null);
  }
  let payload = null;
  try {
    payload = await response.json();
  } catch {
    payload = null;
  }
  if (!response.ok) {
    throw new SandIamPortalTransportError(
      readMessage(isRecord7(payload) ? payload : null) || `\u8BF7\u6C42\u672A\u5B8C\u6210\uFF08HTTP ${String(response.status)}\uFF09`,
      response.status
    );
  }
  const redirectUri = readRedirectUri(payload);
  if (redirectUri === null) throw new SandIamPortalTransportError("\u5916\u90E8\u767B\u5F55\u6CA1\u6709\u8FD4\u56DE\u8DF3\u8F6C\u5730\u5740\u3002", null);
  return { requestId, data: { redirectUri } };
}
async function exchangePortalFederationHandoff(providerCode, applicationCode, code, returnUri, verifier) {
  const requestId = createRequestId();
  let response;
  try {
    response = await fetch(`${SAND_IAM_PORTAL_FEDERATION_PREFIX}/handoff/exchange`, {
      method: "POST",
      credentials: "omit",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        "X-Request-Id": requestId
      },
      body: JSON.stringify({
        provider: providerCode,
        application: applicationCode,
        code,
        return_uri: returnUri,
        verifier
      })
    });
  } catch {
    throw new SandIamPortalTransportError("\u7F51\u7EDC\u4E0D\u53EF\u7528\uFF0C\u8BF7\u68C0\u67E5\u8FDE\u63A5\u540E\u91CD\u8BD5\u3002", null);
  }
  let payload = null;
  try {
    payload = await response.json();
  } catch {
    payload = null;
  }
  if (!response.ok) {
    throw new SandIamPortalTransportError(
      readMessage(isRecord7(payload) ? payload : null) || `\u8BF7\u6C42\u672A\u5B8C\u6210\uFF08HTTP ${String(response.status)}\uFF09`,
      response.status
    );
  }
  const outcome = parsePortalAuthResult(payload);
  if (outcome === null) throw new SandIamPortalTransportError("\u5916\u90E8\u767B\u5F55\u54CD\u5E94\u4E0D\u7B26\u5408\u5DF2\u51BB\u7ED3\u7EA6\u5B9A", null);
  return { requestId, data: outcome };
}
async function portalForgotPassword(organizationCode, applicationCode, identifier, channel) {
  const result = await portalPublicAuth("/password/forgot", {
    organization_code: organizationCode,
    application_code: applicationCode,
    identifier,
    channel
  });
  return { requestId: result.requestId, data: null };
}
async function portalAcceptInvitation(token, username, displayName, password) {
  const requestId = createRequestId();
  let response;
  try {
    response = await fetch(SAND_IAM_PORTAL_INVITATION_ACCEPT, {
      method: "POST",
      credentials: "omit",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        "X-Request-Id": requestId
      },
      body: JSON.stringify({
        token,
        username,
        display_name: displayName,
        password
      })
    });
  } catch {
    throw new SandIamPortalTransportError("\u7F51\u7EDC\u4E0D\u53EF\u7528\uFF0C\u8BF7\u68C0\u67E5\u8FDE\u63A5\u540E\u91CD\u8BD5\u3002", null);
  }
  let parsed = null;
  try {
    parsed = await response.json();
  } catch {
    parsed = null;
  }
  const record = isRecord7(parsed) ? parsed : null;
  if (!response.ok) {
    throw new SandIamPortalTransportError(
      readMessage(record) || `\u8BF7\u6C42\u672A\u5B8C\u6210\uFF08HTTP ${String(response.status)}\uFF09`,
      response.status
    );
  }
  const accepted = parseInvitationAcceptResult(record !== null && "data" in record ? record.data : parsed);
  if (accepted === null) {
    throw new SandIamPortalTransportError("\u63A5\u53D7\u9080\u8BF7\u54CD\u5E94\u4E0D\u7B26\u5408\u5DF2\u51BB\u7ED3\u7EA6\u5B9A", null);
  }
  return { requestId, data: accepted };
}
async function portalResetPassword(organizationCode, applicationCode, identifier, channel, code, password) {
  const result = await portalPublicAuth("/password/reset", {
    organization_code: organizationCode,
    application_code: applicationCode,
    identifier,
    channel,
    code,
    password
  });
  return { requestId: result.requestId, data: null };
}
async function loadPortalProfile(accessToken) {
  const result = await portalRequest("GET", `${SAND_IAM_PORTAL_ME_PREFIX}/profile`, accessToken);
  const profile = parsePortalProfile(result.data);
  if (profile === null) {
    throw new SandIamPortalTransportError("\u8D44\u6599\u8FD4\u56DE\u683C\u5F0F\u4E0D\u7B26\u5408\u5DF2\u51BB\u7ED3\u7EA6\u5B9A", null);
  }
  return { requestId: result.requestId, data: profile };
}
async function logoutPortalSession(accessToken) {
  const result = await portalRequest("POST", `${SAND_IAM_PORTAL_AUTH_PREFIX}/logout`, accessToken);
  return { requestId: result.requestId, data: null };
}
async function updatePortalProfile(accessToken, displayName) {
  const result = await portalRequest(
    "PATCH",
    `${SAND_IAM_PORTAL_ME_PREFIX}/profile`,
    accessToken,
    { display_name: displayName }
  );
  const profile = parsePortalProfile(result.data);
  if (profile === null) {
    throw new SandIamPortalTransportError("\u8D44\u6599\u66F4\u65B0\u8FD4\u56DE\u683C\u5F0F\u4E0D\u7B26\u5408\u5DF2\u51BB\u7ED3\u7EA6\u5B9A", null);
  }
  return { requestId: result.requestId, data: profile };
}
async function loadPortalSecurity(accessToken) {
  const result = await portalRequest("GET", `${SAND_IAM_PORTAL_ME_PREFIX}/security`, accessToken);
  const security = parsePortalSecurity(result.data);
  if (security === null) {
    throw new SandIamPortalTransportError("\u5B89\u5168\u6982\u51B5\u8FD4\u56DE\u683C\u5F0F\u4E0D\u7B26\u5408\u5DF2\u51BB\u7ED3\u7EA6\u5B9A", null);
  }
  return { requestId: result.requestId, data: security };
}
async function loadPortalConnections(accessToken) {
  const result = await portalRequest(
    "GET",
    `${SAND_IAM_PORTAL_ME_PREFIX}/connections`,
    accessToken
  );
  return {
    requestId: result.requestId,
    data: parsePortalConnections(result.data)
  };
}
async function loadPortalSessions(accessToken) {
  const result = await portalRequest("GET", `${SAND_IAM_PORTAL_AUTH_PREFIX}/sessions`, accessToken);
  return { requestId: result.requestId, data: parsePortalSessions(result.data) };
}
async function revokePortalSession(accessToken, sessionId) {
  const result = await portalRequest(
    "POST",
    `${SAND_IAM_PORTAL_AUTH_PREFIX}/sessions/revoke`,
    accessToken,
    { id: sessionId }
  );
  return { requestId: result.requestId, data: null };
}
async function loadPortalFactors(accessToken) {
  const result = await portalRequest(
    "GET",
    `${SAND_IAM_PORTAL_AUTH_PREFIX}/mfa/factors`,
    accessToken
  );
  return { requestId: result.requestId, data: parsePortalFactors(result.data) };
}
async function revokePortalFactor(accessToken, factorId, type, password) {
  const result = await portalRequest(
    "POST",
    `${SAND_IAM_PORTAL_AUTH_PREFIX}/mfa/factors/revoke`,
    accessToken,
    { factor_id: factorId, type, password }
  );
  return { requestId: result.requestId, data: null };
}
async function startPortalTotp(accessToken, name, currentPassword) {
  const result = await portalRequest(
    "POST",
    `${SAND_IAM_PORTAL_AUTH_PREFIX}/mfa/totp/start`,
    accessToken,
    { name, current_password: currentPassword }
  );
  const started = parseTotpStart(result.data);
  if (started === null) throw new SandIamPortalTransportError("\u9A8C\u8BC1\u5668\u521D\u59CB\u5316\u54CD\u5E94\u4E0D\u7B26\u5408\u5DF2\u51BB\u7ED3\u7EA6\u5B9A", null);
  return { requestId: result.requestId, data: started };
}
async function confirmPortalTotp(accessToken, factorId, code) {
  const result = await portalRequest(
    "POST",
    `${SAND_IAM_PORTAL_AUTH_PREFIX}/mfa/totp/confirm`,
    accessToken,
    { factor_id: factorId, code }
  );
  const codes = parseRecoveryCodes(result.data);
  if (codes === null) throw new SandIamPortalTransportError("\u6062\u590D\u7801\u54CD\u5E94\u4E0D\u7B26\u5408\u5DF2\u51BB\u7ED3\u7EA6\u5B9A", null);
  return { requestId: result.requestId, data: codes };
}
async function regeneratePortalRecoveryCodes(accessToken, password) {
  const result = await portalRequest(
    "POST",
    `${SAND_IAM_PORTAL_AUTH_PREFIX}/mfa/recovery/regenerate`,
    accessToken,
    { password }
  );
  const codes = parseRecoveryCodes(result.data);
  if (codes === null) throw new SandIamPortalTransportError("\u6062\u590D\u7801\u54CD\u5E94\u4E0D\u7B26\u5408\u5DF2\u51BB\u7ED3\u7EA6\u5B9A", null);
  return { requestId: result.requestId, data: codes };
}
async function startPortalPasskey(accessToken, name, currentPassword) {
  const result = await portalRequest(
    "POST",
    `${SAND_IAM_PORTAL_AUTH_PREFIX}/passkeys/registration/options`,
    accessToken,
    { name, current_password: currentPassword }
  );
  const payload = isRecord7(result.data) ? result.data : null;
  const challengeToken = payload === null || typeof payload.challenge_token !== "string" ? "" : payload.challenge_token;
  const options = parsePasskeyOptions(result.data);
  if (challengeToken === "" || options === null) {
    throw new SandIamPortalTransportError("\u901A\u884C\u5BC6\u94A5\u521D\u59CB\u5316\u54CD\u5E94\u4E0D\u7B26\u5408\u5DF2\u51BB\u7ED3\u7EA6\u5B9A", null);
  }
  return { requestId: result.requestId, data: { challengeToken, options } };
}
async function finishPortalPasskey(accessToken, payload) {
  const result = await portalRequest(
    "POST",
    `${SAND_IAM_PORTAL_AUTH_PREFIX}/passkeys/registration/finish`,
    accessToken,
    payload
  );
  return { requestId: result.requestId, data: null };
}
async function changePortalPassword(accessToken, currentPassword, newPassword) {
  const result = await portalRequest(
    "POST",
    `${SAND_IAM_PORTAL_AUTH_PREFIX}/password/change`,
    accessToken,
    { current_password: currentPassword, new_password: newPassword }
  );
  return { requestId: result.requestId, data: null };
}
async function loadCasInteraction(requestToken) {
  const requestId = createRequestId();
  let response;
  try {
    const query = new URLSearchParams({ request: requestToken });
    response = await fetch(`${SAND_IAM_PORTAL_CAS_INTERACTION}?${query.toString()}`, {
      method: "GET",
      credentials: "omit",
      headers: {
        Accept: "application/json",
        "X-Request-Id": requestId
      }
    });
  } catch {
    throw new SandIamPortalTransportError("\u7F51\u7EDC\u4E0D\u53EF\u7528\uFF0C\u8BF7\u68C0\u67E5\u8FDE\u63A5\u540E\u91CD\u8BD5\u3002", null);
  }
  let parsed = null;
  try {
    parsed = await response.json();
  } catch {
    parsed = null;
  }
  if (!response.ok) {
    const record = isRecord7(parsed) ? parsed : null;
    throw new SandIamPortalTransportError(
      readMessage(record) || `\u8BF7\u6C42\u672A\u5B8C\u6210\uFF08HTTP ${String(response.status)}\uFF09`,
      response.status
    );
  }
  const interaction = parseCasInteraction(parsed);
  if (interaction === null) {
    throw new SandIamPortalTransportError("CAS \u786E\u8BA4\u4FE1\u606F\u8FD4\u56DE\u683C\u5F0F\u4E0D\u7B26\u5408\u5DF2\u51BB\u7ED3\u7EA6\u5B9A", null);
  }
  return { requestId, data: interaction };
}
async function confirmCasInteraction(accessToken, requestToken) {
  const result = await portalRequest(
    "POST",
    SAND_IAM_PORTAL_CAS_CONFIRM,
    accessToken,
    { request: requestToken }
  );
  const confirm = parseCasConfirm(result.data);
  if (confirm === null) {
    throw new SandIamPortalTransportError("CAS \u786E\u8BA4\u7ED3\u679C\u6CA1\u6709\u8DF3\u8F6C\u5730\u5740\uFF0C\u9875\u9762\u4E0D\u4F1A\u81EA\u884C\u62FC\u63A5 Ticket\u3002", null);
  }
  return { requestId: result.requestId, data: confirm };
}
async function rejectCasInteraction(accessToken, requestToken) {
  const result = await portalRequest(
    "POST",
    SAND_IAM_PORTAL_CAS_REJECT,
    accessToken,
    { request: requestToken }
  );
  const decision = parseCasConfirm(result.data);
  if (decision === null) throw new SandIamPortalTransportError("CAS \u62D2\u7EDD\u7ED3\u679C\u6CA1\u6709\u8FD4\u56DE\u5730\u5740\u3002", null);
  return { requestId: result.requestId, data: decision };
}
async function publicPortalRequest(url) {
  const requestId = createRequestId();
  let response;
  try {
    response = await fetch(url, {
      method: "GET",
      credentials: "omit",
      headers: { Accept: "application/json", "X-Request-Id": requestId }
    });
  } catch {
    throw new SandIamPortalTransportError("\u7F51\u7EDC\u4E0D\u53EF\u7528\uFF0C\u8BF7\u68C0\u67E5\u8FDE\u63A5\u540E\u91CD\u8BD5\u3002", null);
  }
  let parsed = null;
  try {
    parsed = await response.json();
  } catch {
    parsed = null;
  }
  const record = isRecord7(parsed) ? parsed : null;
  if (!response.ok) {
    throw new SandIamPortalTransportError(
      readMessage(record) || `\u8BF7\u6C42\u672A\u5B8C\u6210\uFF08HTTP ${String(response.status)}\uFF09`,
      response.status
    );
  }
  return { requestId, data: record !== null && "data" in record ? record.data : parsed };
}
async function loadOAuthInteraction(requestToken) {
  if (!oauthRequestLooksValid(requestToken)) throw new SandIamPortalTransportError("\u6388\u6743\u786E\u8BA4\u8BF7\u6C42\u65E0\u6548\u3002", 400);
  const result = await publicPortalRequest(
    `${SAND_IAM_PORTAL_OAUTH_INTERACTION}?${new URLSearchParams({ request: requestToken }).toString()}`
  );
  const interaction = parseOAuthInteraction(result.data);
  if (interaction === null) throw new SandIamPortalTransportError("\u6388\u6743\u786E\u8BA4\u4FE1\u606F\u8FD4\u56DE\u683C\u5F0F\u4E0D\u7B26\u5408\u5DF2\u51BB\u7ED3\u7EA6\u5B9A", null);
  return { requestId: result.requestId, data: interaction };
}
async function bindOAuthInteraction(accessToken, requestToken) {
  const result = await portalRequest(
    "POST",
    SAND_IAM_PORTAL_OAUTH_BIND,
    accessToken,
    { authorization_request: requestToken }
  );
  const bound = parseOAuthBoundInteraction(result.data);
  if (bound === null) throw new SandIamPortalTransportError("\u6388\u6743\u786E\u8BA4\u4F1A\u8BDD\u672A\u5EFA\u7ACB\u3002", null);
  return { requestId: result.requestId, data: bound };
}
async function decideOAuthInteraction(accessToken, requestToken, csrfToken, decision) {
  const result = await portalRequest(
    "POST",
    SAND_IAM_PORTAL_OAUTH_CONFIRM,
    accessToken,
    {
      authorization_request: requestToken,
      csrf_token: csrfToken,
      decision
    }
  );
  const completed = parseOAuthDecision(result.data);
  if (completed === null) throw new SandIamPortalTransportError("\u6388\u6743\u7ED3\u679C\u6CA1\u6709\u8FD4\u56DE\u5730\u5740\u3002", null);
  return { requestId: result.requestId, data: completed };
}
function describePortalError(error) {
  const http = error instanceof SandIamPortalTransportError ? error.http : null;
  const detail = error instanceof Error ? error.message : "\u8BF7\u6C42\u672A\u5B8C\u6210";
  if (http === 401) {
    return {
      title: "\u767B\u5F55\u5DF2\u5931\u6548",
      detail: "\u8BF7\u91CD\u65B0\u767B\u5F55\u540E\u518D\u8BD5\u3002\u9875\u9762\u4E0D\u4F1A\u4FDD\u5B58\u4EE4\u724C\u660E\u6587\u3002",
      http
    };
  }
  if (http === 403) {
    return {
      title: "\u6CA1\u6709\u6743\u9650",
      detail: "\u5F53\u524D\u5E94\u7528\u7528\u6237\u65E0\u6743\u6267\u884C\u6B64\u64CD\u4F5C\u3002\u8FD9\u4E0E\u6CA1\u6709\u6570\u636E\u4E0D\u540C\u3002",
      http
    };
  }
  if (http === 409) {
    return { title: "\u4E0E\u73B0\u6709\u914D\u7F6E\u51B2\u7A81", detail, http };
  }
  if (http === 410) {
    return {
      title: "\u9080\u8BF7\u5DF2\u8FC7\u671F",
      detail: "\u8BE5\u9080\u8BF7\u94FE\u63A5\u5DF2\u8FC7\u671F\uFF0C\u4E0D\u80FD\u91CD\u653E\u3002\u8BF7\u8054\u7CFB\u5E94\u7528\u7BA1\u7406\u5458\u91CD\u53D1\u3002",
      http
    };
  }
  if (http === 423) {
    return { title: "\u8D26\u53F7\u5DF2\u9501\u5B9A", detail, http };
  }
  if (http === 404) {
    if (detail.includes("CAS") || detail.includes("SAND_IAM_CAS_")) {
      return { title: "CAS \u8BF7\u6C42\u672A\u88AB\u63A5\u53D7", detail, http };
    }
    return {
      title: "\u767B\u5F55\u5916\u89C2\u672A\u914D\u7F6E",
      detail: "\u8BE5\u5E94\u7528\u8FD8\u6CA1\u6709\u542F\u7528\u7684\u767B\u5F55\u5916\u89C2\u3002\u8FD9\u4E0D\u662F\u767B\u5F55\u6210\u529F\u3002",
      http
    };
  }
  if (http === 503) {
    return {
      title: "\u670D\u52A1\u6682\u65F6\u4E0D\u53EF\u7528",
      detail: "\u8BF7\u7A0D\u540E\u91CD\u8BD5\uFF1B\u6301\u7EED\u5931\u8D25\u65F6\u8054\u7CFB\u5E94\u7528\u7BA1\u7406\u5458\u3002",
      http
    };
  }
  return { title: "\u8BF7\u6C42\u672A\u5B8C\u6210", detail, http };
}

// sand-iam/portal/src/app.ts
var params = new URLSearchParams(window.location.search);
var recovery = null;
var securityOperation = null;
var pendingTotp = null;
function securityCurrent(operation) {
  return operation.token === state.accessToken && operation.submission === authSubmission;
}
function beginSecurityOperation() {
  if (state.accessToken === "" || securityOperation !== null && securityCurrent(securityOperation)) return null;
  securityOperation = { token: state.accessToken, submission: authSubmission };
  updateDecisionButtons();
  return securityOperation;
}
function updateDecisionButtons() {
  const busy = securityOperation !== null && securityCurrent(securityOperation);
  for (const id of ["confirm-cas", "reject-cas", "approve-oauth", "deny-oauth", "logout-btn"]) {
    const button = document.getElementById(id);
    if (button instanceof HTMLButtonElement) button.disabled = busy;
  }
}
function finishSecurityOperation(operation) {
  if (securityOperation === operation) securityOperation = null;
  if (securityCurrent(operation)) render();
}
function recoveryDraft() {
  if (recovery === null || recovery.organization !== state.organizationCode || recovery.application !== state.applicationCode) {
    recovery = {
      organization: state.organizationCode,
      application: state.applicationCode,
      identifier: "",
      channel: "email",
      busy: false
    };
  }
  return recovery;
}
var captchas = {
  login: { widget: null, ready: false, optional: false, attempt: 0, organization: "", application: "" },
  register: { widget: null, ready: false, optional: false, attempt: 0, organization: "", application: "" }
};
var captchaGeneration = 0;
var authBusy = false;
var authSubmission = 0;
function loginCurrent(attempt) {
  return attempt.submission === authSubmission && attempt.organization === state.organizationCode && attempt.application === state.applicationCode;
}
function beginAlternativeLogin() {
  if (authBusy) return null;
  authBusy = true;
  const attempt = {
    organization: state.organizationCode,
    application: state.applicationCode,
    submission: ++authSubmission
  };
  updateCaptchaButton("login");
  updateCaptchaButton("register");
  return attempt;
}
function finishAlternativeLogin(attempt) {
  if (attempt.submission !== authSubmission) return;
  authBusy = false;
  updateCaptchaButton("login");
  updateCaptchaButton("register");
  if (loginCurrent(attempt)) render();
}
function updateCaptchaButton(action) {
  const button = document.getElementById(`${action}-btn`);
  if (button instanceof HTMLButtonElement) button.disabled = authBusy || !captchas[action].ready;
  const retry = document.getElementById(`${action}-captcha-retry`);
  if (retry instanceof HTMLButtonElement) retry.disabled = authBusy;
  if (action === "login") {
    for (const id of ["passkey-login-btn", "mfa-login-btn", "mfa-passkey-btn"]) {
      const button2 = document.getElementById(id);
      if (button2 instanceof HTMLButtonElement) button2.disabled = authBusy;
    }
  }
}
function clearCaptchas() {
  captchaGeneration += 1;
  for (const action of ["login", "register"]) {
    captchas[action].widget?.destroy();
    captchas[action] = { widget: null, ready: false, optional: false, attempt: 0, organization: "", application: "" };
  }
}
function renderCaptcha(action) {
  return `<div id="${action}-captcha-widget"></div>
    <p id="${action}-captcha-status" role="status" aria-live="polite">\u6B63\u5728\u8BFB\u53D6\u4EBA\u673A\u9A8C\u8BC1\u914D\u7F6E\u2026</p>
    <button type="button" id="${action}-captcha-retry" hidden>\u91CD\u65B0\u9A8C\u8BC1</button>`;
}
async function mountCaptcha(action) {
  const container = document.getElementById(`${action}-captcha-widget`);
  const status = document.getElementById(`${action}-captcha-status`);
  const retry = document.getElementById(`${action}-captcha-retry`);
  if (container === null || status === null || !(retry instanceof HTMLButtonElement)) return;
  const generation = captchaGeneration;
  const attempt = ++captchas[action].attempt;
  const organization = state.organizationCode;
  const application = state.applicationCode;
  const current = () => generation === captchaGeneration && attempt === captchas[action].attempt && organization === state.organizationCode && application === state.applicationCode;
  const show = (text, ready, retryable) => {
    if (!current()) return;
    status.textContent = text;
    captchas[action].ready = ready;
    retry.hidden = !retryable;
    retry.disabled = authBusy;
    updateCaptchaButton(action);
  };
  const messages = {
    loading: "\u6B63\u5728\u52A0\u8F7D\u4EBA\u673A\u9A8C\u8BC1\u2026",
    waiting: "\u8BF7\u5B8C\u6210\u4EBA\u673A\u9A8C\u8BC1",
    verified: "\u4EBA\u673A\u9A8C\u8BC1\u5DF2\u5B8C\u6210",
    expired: "\u9A8C\u8BC1\u5DF2\u8FC7\u671F\uFF0C\u8BF7\u91CD\u65B0\u9A8C\u8BC1\u3002",
    error: "\u4EBA\u673A\u9A8C\u8BC1\u5931\u8D25\uFF0C\u8BF7\u91CD\u8BD5\u3002",
    destroyed: "\u8BF7\u91CD\u65B0\u5B8C\u6210\u4EBA\u673A\u9A8C\u8BC1\u3002"
  };
  captchas[action].widget?.destroy();
  captchas[action] = { widget: null, ready: false, optional: false, attempt, organization, application };
  show("\u6B63\u5728\u8BFB\u53D6\u4EBA\u673A\u9A8C\u8BC1\u914D\u7F6E\u2026", false, false);
  retry.onclick = () => {
    if (!authBusy) void mountCaptcha(action);
  };
  try {
    const result = await loadPortalCaptchaConfiguration(organization, application, action);
    if (!current()) return;
    if (!result.data.required) {
      captchas[action].optional = true;
      show("\u5F53\u524D\u65E0\u9700\u4EBA\u673A\u9A8C\u8BC1\u3002", true, false);
    } else if (!result.data.available) {
      show("\u4EBA\u673A\u9A8C\u8BC1\u670D\u52A1\u6682\u4E0D\u53EF\u7528\uFF0C\u8BF7\u7A0D\u540E\u91CD\u8BD5\u6216\u8054\u7CFB\u7BA1\u7406\u5458\u3002", false, true);
    } else {
      const widget = new CaptchaWidget(container, (value) => {
        show(messages[value], value === "verified", value === "expired" || value === "error" || value === "destroyed");
      });
      captchas[action].widget = widget;
      await widget.mount(result.data.widget);
    }
  } catch (error) {
    show(error instanceof Error ? error.message : "\u4EBA\u673A\u9A8C\u8BC1\u914D\u7F6E\u52A0\u8F7D\u5931\u8D25\uFF0C\u8BF7\u91CD\u8BD5\u3002", false, true);
  }
}
function takeCaptcha(action) {
  if (authBusy || !captchas[action].ready) return null;
  if (captchas[action].organization !== state.organizationCode || captchas[action].application !== state.applicationCode) return null;
  authBusy = true;
  updateCaptchaButton("login");
  updateCaptchaButton("register");
  if (captchas[action].optional) return "";
  const token = captchas[action].widget?.takeToken() ?? "";
  if (token !== "") return token;
  authBusy = false;
  updateCaptchaButton("login");
  updateCaptchaButton("register");
  return null;
}
function takeInvitationToken() {
  const token = params.get("token") ?? "";
  if (token === "") return "";
  params.delete("token");
  const query = params.toString();
  window.history.replaceState(
    {},
    "",
    `${window.location.pathname}${query === "" ? "" : `?${query}`}${window.location.hash}`
  );
  return token;
}
function takeSensitiveQuery(name) {
  const request = params.get(name) ?? "";
  if (request === "") return "";
  params.delete(name);
  const query = params.toString();
  window.history.replaceState(
    {},
    "",
    `${window.location.pathname}${query === "" ? "" : `?${query}`}${window.location.hash}`
  );
  return request;
}
var federationCallback = takeFederationCallback();
var invitationToken = takeInvitationToken();
var invitationAcceptedName = "";
var invitationUsername = "";
var invitationDisplayName = "";
var acceptingInvitation = null;
function invitationCurrent(attempt) {
  return loginCurrent(attempt) && attempt.token === invitationToken && attempt.accessToken === state.accessToken;
}
var casRequest = takeSensitiveQuery("cas_request") || takeSensitiveQuery("request");
var oauthRequest = takeSensitiveQuery("oauth_request");
var state = {
  verification: null,
  accessToken: "",
  requestId: "",
  organizationCode: params.get("organization_code") ?? "",
  applicationCode: params.get("application_code") ?? "",
  experience: null,
  profile: null,
  security: null,
  sessions: [],
  factors: [],
  connections: [],
  cas: null,
  oauth: null,
  oauthBound: null,
  recoveryCodes: [],
  pendingMfa: null,
  errorTitle: "",
  errorDetail: "",
  usingDefaultExperience: false
};
var federationStoragePrefix = "sand-iam.portal.federation.";
function isRecord8(value) {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}
function isInteractionExperienceUnavailable(error) {
  const http = describePortalError(error).http;
  return http === 404 || http === 503;
}
function useDefaultExperience() {
  state.experience = defaultPasswordExperience(state.organizationCode, state.applicationCode);
  state.usingDefaultExperience = true;
}
function clearDefaultExperience() {
  state.usingDefaultExperience = false;
}
function currentPortalReturnUri() {
  return `${window.location.origin}${window.location.pathname}`;
}
function randomBase64Url(bytes) {
  if (typeof crypto === "undefined" || typeof crypto.getRandomValues !== "function") {
    throw new Error("\u5F53\u524D\u6D4F\u89C8\u5668\u65E0\u6CD5\u5B89\u5168\u53D1\u8D77\u5916\u90E8\u767B\u5F55\u3002");
  }
  const value = crypto.getRandomValues(new Uint8Array(bytes));
  let binary = "";
  for (const item of value) binary += String.fromCharCode(item);
  return btoa(binary).replaceAll("+", "-").replaceAll("/", "_").replace(/=+$/u, "");
}
async function handoffChallenge(verifier) {
  if (typeof crypto === "undefined" || crypto.subtle === void 0) {
    throw new Error("\u5F53\u524D\u6D4F\u89C8\u5668\u65E0\u6CD5\u5B89\u5168\u53D1\u8D77\u5916\u90E8\u767B\u5F55\u3002");
  }
  const digest = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(verifier));
  const bytes = new Uint8Array(digest);
  let binary = "";
  for (const item of bytes) binary += String.fromCharCode(item);
  return btoa(binary).replaceAll("+", "-").replaceAll("/", "_").replace(/=+$/u, "");
}
function saveFederationContext(stateKey, context) {
  try {
    sessionStorage.setItem(`${federationStoragePrefix}${stateKey}`, JSON.stringify(context));
  } catch {
    throw new Error("\u6D4F\u89C8\u5668\u62D2\u7EDD\u4FDD\u5B58\u672C\u6B21\u5916\u90E8\u767B\u5F55\u786E\u8BA4\u4FE1\u606F\uFF0C\u8BF7\u5141\u8BB8\u6B64\u7AD9\u70B9\u4F7F\u7528\u4E34\u65F6\u4F1A\u8BDD\u5B58\u50A8\u540E\u91CD\u8BD5\u3002");
  }
}
function takeFederationContext(stateKey) {
  try {
    const stored = sessionStorage.getItem(`${federationStoragePrefix}${stateKey}`);
    sessionStorage.removeItem(`${federationStoragePrefix}${stateKey}`);
    if (stored === null) return null;
    const parsed = JSON.parse(stored);
    if (!isRecord8(parsed) || typeof parsed.providerCode !== "string" || typeof parsed.applicationCode !== "string" || typeof parsed.returnUri !== "string" || typeof parsed.verifier !== "string" || typeof parsed.oauthRequest !== "string" || typeof parsed.casRequest !== "string") {
      return null;
    }
    return {
      providerCode: parsed.providerCode,
      organizationCode: typeof parsed.organizationCode === "string" ? parsed.organizationCode : "",
      applicationCode: parsed.applicationCode,
      returnUri: parsed.returnUri,
      verifier: parsed.verifier,
      oauthRequest: parsed.oauthRequest,
      casRequest: parsed.casRequest
    };
  } catch {
    return null;
  }
}
function takeFederationCallback() {
  const code = params.get("code") ?? "";
  const stateKey = params.get("state") ?? "";
  if (code === "" && stateKey === "") return null;
  params.delete("code");
  params.delete("state");
  const query = params.toString();
  window.history.replaceState(
    {},
    "",
    `${window.location.pathname}${query === "" ? "" : `?${query}`}${window.location.hash}`
  );
  if (!/^fh_[a-f0-9]{64}$/.test(code) || !/^fhr_[A-Za-z0-9_-]{43}$/.test(stateKey)) return null;
  return { code, state: stateKey };
}
function root() {
  const element = document.getElementById("app");
  if (element === null) throw new Error("\u7F3A\u5C11 #app");
  return element;
}
function inputValue(id) {
  const element = document.getElementById(id);
  return element instanceof HTMLInputElement ? element.value : "";
}
function setError(error) {
  const described = describePortalError(error);
  state.errorTitle = described.title;
  state.errorDetail = described.detail;
}
function clearError() {
  state.errorTitle = "";
  state.errorDetail = "";
}
function rememberRequest(requestId) {
  state.requestId = requestId;
}
async function loadCas(attempt) {
  const submission = authSubmission;
  const request = casRequest;
  const current = () => submission === authSubmission && request === casRequest;
  if (!casRequestLooksValid(casRequest)) {
    state.errorTitle = "CAS \u8BF7\u6C42\u672A\u88AB\u63A5\u53D7";
    state.errorDetail = "\u7F3A\u5C11\u6709\u6548\u786E\u8BA4\u8BF7\u6C42\u3002\u8BF7\u4ECE\u5E94\u7528\u91CD\u65B0\u53D1\u8D77\uFF0C\u4E0D\u8981\u624B\u586B request\u3002";
    state.cas = null;
    render();
    return;
  }
  clearError();
  try {
    const result = await loadCasInteraction(request);
    if (!current()) return;
    if (attempt !== void 0 && (attempt.application !== result.data.applicationCode || attempt.organization !== "" && attempt.organization !== result.data.organizationCode)) {
      throw new Error("\u5E94\u7528\u767B\u5F55\u8BF7\u6C42\u4E0E\u672C\u6B21\u5916\u90E8\u767B\u5F55\u4E0D\u5339\u914D\u3002");
    }
    rememberRequest(result.requestId);
    state.cas = result.data;
    state.organizationCode = result.data.organizationCode;
    state.applicationCode = result.data.applicationCode;
    if (attempt !== void 0) attempt.organization = result.data.organizationCode;
    try {
      const experience = await loadPublicExperience(state.organizationCode, state.applicationCode);
      if (!current()) return;
      rememberRequest(experience.requestId);
      state.experience = experience.data;
      clearDefaultExperience();
    } catch (experienceError) {
      if (!current()) return;
      if (isInteractionExperienceUnavailable(experienceError)) {
        useDefaultExperience();
      } else {
        setError(experienceError);
        state.experience = null;
        clearDefaultExperience();
      }
    }
  } catch (error) {
    if (!current()) return;
    setError(error);
    state.cas = null;
  }
  render();
}
async function submitCasConfirm() {
  if (state.accessToken === "") {
    state.errorTitle = "\u8FD8\u6CA1\u6709\u5E94\u7528\u7528\u6237\u4F1A\u8BDD";
    state.errorDetail = "\u8BF7\u5148\u7528\u5F53\u524D\u63A5\u5165\u5E94\u7528\u7684\u8D26\u53F7\u767B\u5F55\u3002\u7BA1\u7406\u7AEF\u767B\u5F55\u4E0D\u80FD\u4EE3\u66FF\u786E\u8BA4\u3002";
    render();
    return;
  }
  if (!casRequestLooksValid(casRequest)) {
    state.errorTitle = "CAS \u8BF7\u6C42\u672A\u88AB\u63A5\u53D7";
    state.errorDetail = "\u786E\u8BA4\u8BF7\u6C42\u5DF2\u5931\u6548\uFF0C\u8BF7\u4ECE\u5E94\u7528\u91CD\u65B0\u53D1\u8D77\u3002";
    render();
    return;
  }
  const operation = beginSecurityOperation();
  if (operation === null) return;
  const request = casRequest;
  const current = () => securityCurrent(operation) && request === casRequest;
  let redirected = false;
  clearError();
  try {
    const result = await confirmCasInteraction(operation.token, request);
    if (!current()) return;
    rememberRequest(result.requestId);
    window.location.assign(result.data.redirectUri);
    redirected = true;
  } catch (error) {
    if (!current()) return;
    setError(error);
  } finally {
    if (!redirected) finishSecurityOperation(operation);
  }
}
async function submitCasReject() {
  if (state.accessToken === "" || !casRequestLooksValid(casRequest)) {
    state.errorTitle = "\u65E0\u6CD5\u62D2\u7EDD\u6B64\u8BF7\u6C42";
    state.errorDetail = "\u8BF7\u5148\u767B\u5F55\uFF0C\u5E76\u4ECE\u5E94\u7528\u91CD\u65B0\u53D1\u8D77 CAS \u767B\u5F55\u3002";
    render();
    return;
  }
  const operation = beginSecurityOperation();
  if (operation === null) return;
  const request = casRequest;
  const current = () => securityCurrent(operation) && request === casRequest;
  let redirected = false;
  clearError();
  try {
    const result = await rejectCasInteraction(operation.token, request);
    if (!current()) return;
    rememberRequest(result.requestId);
    window.location.assign(result.data.redirectUri);
    redirected = true;
  } catch (error) {
    if (!current()) return;
    setError(error);
  } finally {
    if (!redirected) finishSecurityOperation(operation);
  }
}
async function loadOAuth(attempt) {
  const submission = authSubmission;
  const request = oauthRequest;
  const current = () => submission === authSubmission && request === oauthRequest;
  if (!oauthRequestLooksValid(oauthRequest)) {
    state.oauth = null;
    return;
  }
  clearError();
  try {
    const result = await loadOAuthInteraction(request);
    if (!current()) return;
    if (attempt !== void 0 && (attempt.application !== result.data.applicationCode || attempt.organization !== "" && attempt.organization !== result.data.organizationCode)) {
      throw new Error("\u5E94\u7528\u6388\u6743\u8BF7\u6C42\u4E0E\u672C\u6B21\u5916\u90E8\u767B\u5F55\u4E0D\u5339\u914D\u3002");
    }
    rememberRequest(result.requestId);
    state.oauth = result.data;
    state.organizationCode = result.data.organizationCode;
    state.applicationCode = result.data.applicationCode;
    if (attempt !== void 0) attempt.organization = result.data.organizationCode;
    try {
      const experience = await loadPublicExperience(state.organizationCode, state.applicationCode);
      if (!current()) return;
      rememberRequest(experience.requestId);
      state.experience = experience.data;
      clearDefaultExperience();
    } catch (experienceError) {
      if (!current()) return;
      if (isInteractionExperienceUnavailable(experienceError)) {
        useDefaultExperience();
      } else {
        setError(experienceError);
        state.experience = null;
        clearDefaultExperience();
      }
    }
  } catch (error) {
    if (!current()) return;
    setError(error);
    state.oauth = null;
  }
  render();
}
async function bindOAuthAfterLogin() {
  const submission = authSubmission;
  const accessToken = state.accessToken;
  if (state.accessToken === "" || !oauthRequestLooksValid(oauthRequest)) return;
  try {
    const result = await bindOAuthInteraction(state.accessToken, oauthRequest);
    if (submission !== authSubmission || accessToken !== state.accessToken) return;
    rememberRequest(result.requestId);
    state.oauthBound = result.data;
  } catch (error) {
    if (submission !== authSubmission || accessToken !== state.accessToken) return;
    setError(error);
    state.oauthBound = null;
  }
}
async function submitOAuthDecision(decision) {
  if (state.accessToken === "" || state.oauthBound === null || !oauthRequestLooksValid(oauthRequest)) {
    state.errorTitle = "\u8FD8\u4E0D\u80FD\u5B8C\u6210\u6388\u6743";
    state.errorDetail = "\u8BF7\u5148\u4F7F\u7528\u8BE5\u5E94\u7528\u7684\u8D26\u53F7\u767B\u5F55\u3002";
    render();
    return;
  }
  const operation = beginSecurityOperation();
  if (operation === null) return;
  const request = oauthRequest;
  const bound = state.oauthBound;
  const current = () => securityCurrent(operation) && request === oauthRequest && bound === state.oauthBound;
  let redirected = false;
  clearError();
  try {
    const result = await decideOAuthInteraction(
      operation.token,
      request,
      bound.csrfToken,
      decision
    );
    if (!current()) return;
    rememberRequest(result.requestId);
    window.location.assign(result.data.redirectUri);
    redirected = true;
  } catch (error) {
    if (!current()) return;
    setError(error);
  } finally {
    if (!redirected) finishSecurityOperation(operation);
  }
}
async function loadExperience() {
  pendingTotp = null;
  securityOperation = null;
  recovery = null;
  authSubmission += 1;
  authBusy = false;
  clearCaptchas();
  state.verification = null;
  state.organizationCode = inputValue("organization-code") || state.organizationCode;
  state.applicationCode = inputValue("application-code") || state.applicationCode;
  state.accessToken = "";
  state.profile = null;
  state.security = null;
  state.sessions = [];
  state.factors = [];
  state.connections = [];
  state.pendingMfa = null;
  state.oauthBound = null;
  state.recoveryCodes = [];
  const submission = authSubmission;
  state.experience = null;
  clearError();
  render();
  try {
    const result = await loadPublicExperience(
      state.organizationCode,
      state.applicationCode
    );
    if (submission !== authSubmission) return;
    rememberRequest(result.requestId);
    state.experience = result.data;
    clearDefaultExperience();
  } catch (error) {
    if (submission !== authSubmission) return;
    setError(error);
    state.experience = null;
    clearDefaultExperience();
  }
  render();
}
async function submitLogin() {
  if (authBusy) return;
  if (state.experience === null || !experienceAllowsPassword(state.experience)) {
    state.errorTitle = "\u8BE5\u767B\u5F55\u65B9\u5F0F\u5DF2\u5173\u95ED";
    state.errorDetail = "\u5F53\u524D\u5916\u89C2\u672A\u542F\u7528\u5BC6\u7801\u767B\u5F55\uFF0C\u9875\u9762\u4E0D\u4F1A\u63D0\u4EA4\u3002";
    render();
    return;
  }
  const captcha = takeCaptcha("login");
  if (captcha === null) return;
  const submission = ++authSubmission;
  clearError();
  const identifier = inputValue("login-identifier");
  const organization = state.organizationCode;
  const application = state.applicationCode;
  try {
    const result = await portalLogin(
      state.organizationCode,
      state.applicationCode,
      identifier,
      inputValue("login-password"),
      captcha
    );
    if (submission !== authSubmission || organization !== state.organizationCode || application !== state.applicationCode) return;
    rememberRequest(result.requestId);
    if (result.data.verificationRequired) {
      openVerification(identifier, organization, application);
      render();
      return;
    }
    if (result.data.mfaRequired) {
      state.pendingMfa = result.data;
      state.errorTitle = "\u9700\u8981\u8FDB\u884C\u5B89\u5168\u9A8C\u8BC1";
      state.errorDetail = "\u8BF7\u9009\u62E9\u5DF2\u7ECF\u8BBE\u7F6E\u7684\u9A8C\u8BC1\u65B9\u5F0F\u540E\u7EE7\u7EED\u3002";
      render();
      return;
    }
    state.accessToken = result.data.accessToken;
    await loadAll();
    if (submission !== authSubmission) return;
    await bindOAuthAfterLogin();
    if (submission !== authSubmission) return;
    render();
  } catch (error) {
    if (submission !== authSubmission || organization !== state.organizationCode || application !== state.applicationCode) return;
    if (error instanceof Error && error.message.includes("SAND_IAM_AUTH_VERIFICATION_REQUIRED")) {
      openVerification(identifier, organization, application);
    } else {
      setError(error);
    }
    render();
  } finally {
    if (submission === authSubmission) {
      authBusy = false;
      updateCaptchaButton("login");
      updateCaptchaButton("register");
    }
  }
}
async function completePrimaryLogin(outcome, attempt = {
  organization: state.organizationCode,
  application: state.applicationCode,
  submission: authSubmission
}) {
  if (!loginCurrent(attempt)) return;
  if (outcome.verificationRequired) {
    state.errorTitle = "\u8FD8\u9700\u8981\u5B8C\u6210\u9A8C\u8BC1";
    state.errorDetail = "\u8D26\u53F7\u5DF2\u8BC6\u522B\uFF0C\u4F46\u8FD8\u4E0D\u80FD\u7B7E\u53D1\u4F1A\u8BDD\u3002\u8BF7\u5148\u5B8C\u6210\u90AE\u7BB1\u6216\u624B\u673A\u9A8C\u8BC1\u3002";
    return;
  }
  if (outcome.mfaRequired) {
    state.pendingMfa = outcome;
    state.errorTitle = "\u9700\u8981\u8FDB\u884C\u5B89\u5168\u9A8C\u8BC1";
    state.errorDetail = "\u8BF7\u9009\u62E9\u5DF2\u7ECF\u8BBE\u7F6E\u7684\u9A8C\u8BC1\u65B9\u5F0F\u540E\u7EE7\u7EED\u3002";
    return;
  }
  state.pendingMfa = null;
  state.accessToken = outcome.accessToken;
  await loadAll();
  if (!loginCurrent(attempt)) return;
  await bindOAuthAfterLogin();
  if (!loginCurrent(attempt)) return;
}
async function submitPasskeyLogin() {
  if (authBusy) return;
  if (state.experience === null || !experienceAllowsPasskey(state.experience)) {
    state.errorTitle = "\u8BE5\u767B\u5F55\u65B9\u5F0F\u5DF2\u5173\u95ED";
    state.errorDetail = "\u5F53\u524D\u5E94\u7528\u6CA1\u6709\u542F\u7528\u901A\u884C\u5BC6\u94A5\u767B\u5F55\u3002";
    render();
    return;
  }
  const attempt = beginAlternativeLogin();
  if (attempt === null) return;
  clearError();
  try {
    const started = await startPortalPasskeyLogin(attempt.organization, attempt.application);
    if (!loginCurrent(attempt)) return;
    rememberRequest(started.requestId);
    const options = parsePasskeyAssertionOptions(started.data.publicKey);
    if (options === null) throw new Error("\u901A\u884C\u5BC6\u94A5\u767B\u5F55\u4FE1\u606F\u4E0D\u5B8C\u6574\u3002");
    const assertion = await getPasskeyAssertion(options);
    if (!loginCurrent(attempt)) return;
    const completed = await finishPortalPasskeyLogin(
      attempt.organization,
      attempt.application,
      started.data.challengeToken,
      assertion
    );
    if (!loginCurrent(attempt)) return;
    rememberRequest(completed.requestId);
    await completePrimaryLogin(completed.data, attempt);
  } catch (error) {
    if (!loginCurrent(attempt)) return;
    setError(error);
  } finally {
    finishAlternativeLogin(attempt);
  }
}
async function startExternalLogin(protocol, providerCode) {
  if (state.experience === null || authBusy) return;
  const attempt = beginAlternativeLogin();
  if (attempt === null) return;
  const originalOAuthRequest = oauthRequest;
  const originalCasRequest = casRequest;
  let storedKey = null;
  let redirected = false;
  clearError();
  try {
    const returnUri = currentPortalReturnUri();
    const stateKey = `fhr_${randomBase64Url(32)}`;
    const verifier = randomBase64Url(32);
    const challenge = await handoffChallenge(verifier);
    if (!loginCurrent(attempt)) return;
    saveFederationContext(stateKey, {
      providerCode,
      organizationCode: attempt.organization,
      applicationCode: attempt.application,
      returnUri,
      verifier,
      oauthRequest: originalOAuthRequest,
      casRequest: originalCasRequest
    });
    storedKey = stateKey;
    const started = await startPortalFederationLogin(
      protocol,
      providerCode,
      attempt.application,
      returnUri,
      stateKey,
      challenge
    );
    if (!loginCurrent(attempt)) return;
    rememberRequest(started.requestId);
    window.location.assign(started.data.redirectUri);
    redirected = true;
  } catch (error) {
    if (!loginCurrent(attempt)) return;
    setError(error);
  } finally {
    if (!redirected) {
      if (storedKey !== null) {
        try {
          sessionStorage.removeItem(`${federationStoragePrefix}${storedKey}`);
        } catch {
          if (loginCurrent(attempt)) setError(new Error("\u6D4F\u89C8\u5668\u672A\u80FD\u6E05\u9664\u672C\u6B21\u5916\u90E8\u767B\u5F55\u786E\u8BA4\u4FE1\u606F\uFF0C\u8BF7\u5173\u95ED\u5F53\u524D\u6807\u7B7E\u9875\u540E\u91CD\u8BD5\u3002"));
        }
      }
      finishAlternativeLogin(attempt);
    }
  }
}
async function completeFederationCallback() {
  if (federationCallback === null || authBusy) return;
  const context = takeFederationContext(federationCallback.state);
  if (context === null) {
    state.errorTitle = "\u5916\u90E8\u767B\u5F55\u672A\u5B8C\u6210";
    state.errorDetail = "\u672C\u6B21\u767B\u5F55\u786E\u8BA4\u4FE1\u606F\u5DF2\u5931\u6548\u3002\u8BF7\u56DE\u5230\u5E94\u7528\u91CD\u65B0\u9009\u62E9\u767B\u5F55\u65B9\u5F0F\u3002";
    render();
    return;
  }
  state.organizationCode = context.organizationCode;
  state.applicationCode = context.applicationCode;
  const attempt = beginAlternativeLogin();
  if (attempt === null) return;
  clearError();
  try {
    const completed = await exchangePortalFederationHandoff(
      context.providerCode,
      context.applicationCode,
      federationCallback.code,
      context.returnUri,
      context.verifier
    );
    if (!loginCurrent(attempt)) return;
    rememberRequest(completed.requestId);
    oauthRequest = context.oauthRequest;
    casRequest = context.casRequest;
    if (oauthRequestLooksValid(oauthRequest)) {
      await loadOAuth(attempt);
      if (!loginCurrent(attempt)) return;
      if (state.oauth === null) throw new Error("\u5E94\u7528\u6388\u6743\u8BF7\u6C42\u65E0\u6CD5\u6062\u590D\uFF0C\u8BF7\u4ECE\u5E94\u7528\u91CD\u65B0\u53D1\u8D77\u767B\u5F55\u3002");
    }
    if (casRequestLooksValid(casRequest)) {
      await loadCas(attempt);
      if (!loginCurrent(attempt)) return;
      if (state.cas === null) throw new Error("\u5E94\u7528\u767B\u5F55\u8BF7\u6C42\u65E0\u6CD5\u6062\u590D\uFF0C\u8BF7\u4ECE\u5E94\u7528\u91CD\u65B0\u53D1\u8D77\u767B\u5F55\u3002");
    }
    await completePrimaryLogin(completed.data, attempt);
  } catch (error) {
    if (!loginCurrent(attempt)) return;
    setError(error);
  } finally {
    finishAlternativeLogin(attempt);
  }
}
async function submitRegister() {
  if (authBusy) return;
  if (state.experience === null || !experienceAllowsRegister(state.experience)) {
    state.errorTitle = "\u6CE8\u518C\u5DF2\u5173\u95ED";
    state.errorDetail = "\u5F53\u524D\u5916\u89C2\u672A\u5F00\u653E\u6CE8\u518C\u3002\u9080\u8BF7\u6CE8\u518C\u8BF7\u8D70\u9080\u8BF7\u94FE\u63A5\u3002";
    render();
    return;
  }
  const captcha = takeCaptcha("register");
  if (captcha === null) return;
  const submission = ++authSubmission;
  clearError();
  const fields = {
    username: inputValue("register-username"),
    password: inputValue("register-password")
  };
  for (const field of state.experience.registrationFields) {
    if (field !== "username") fields[field] = inputValue(`register-${field}`);
  }
  if (captcha !== "") fields.captcha_token = captcha;
  const organization = state.organizationCode;
  const application = state.applicationCode;
  try {
    const result = await portalRegister(
      state.organizationCode,
      state.applicationCode,
      fields
    );
    if (submission !== authSubmission || organization !== state.organizationCode || application !== state.applicationCode) return;
    rememberRequest(result.requestId);
    if (result.data.verificationRequired) {
      openVerification(fields.username, organization, application);
      render();
      return;
    }
    if (result.data.mfaRequired) {
      state.pendingMfa = result.data;
      state.errorTitle = "\u9700\u8981\u8FDB\u884C\u5B89\u5168\u9A8C\u8BC1";
      state.errorDetail = "\u8BF7\u9009\u62E9\u5DF2\u7ECF\u8BBE\u7F6E\u7684\u9A8C\u8BC1\u65B9\u5F0F\u540E\u7EE7\u7EED\u3002";
      render();
      return;
    }
    state.accessToken = result.data.accessToken;
    await loadAll();
    if (submission !== authSubmission) return;
    await bindOAuthAfterLogin();
    if (submission !== authSubmission) return;
    render();
  } catch (error) {
    if (submission !== authSubmission || organization !== state.organizationCode || application !== state.applicationCode) return;
    setError(error);
    render();
  } finally {
    if (submission === authSubmission) {
      authBusy = false;
      updateCaptchaButton("login");
      updateCaptchaButton("register");
    }
  }
}
function openVerification(identifier, organization, application) {
  if (organization !== state.organizationCode || application !== state.applicationCode) return;
  state.verification = {
    organizationCode: organization,
    applicationCode: application,
    identifier,
    channel: "email",
    busy: false
  };
  state.pendingMfa = null;
  state.errorTitle = "\u8FD8\u9700\u8981\u5B8C\u6210\u9A8C\u8BC1";
  state.errorDetail = "\u8BF7\u9009\u62E9\u8D26\u53F7\u767B\u8BB0\u7684\u90AE\u7BB1\u6216\u624B\u673A\u5B8C\u6210\u9A8C\u8BC1\uFF0C\u518D\u91CD\u65B0\u767B\u5F55\u3002";
}
async function submitVerification(channel) {
  const verification = state.verification;
  if (verification === null || verification.busy) return;
  const code = channel === void 0 ? inputValue("verification-code").trim() : void 0;
  if (code === "") {
    state.errorTitle = "\u8BF7\u586B\u5199\u9A8C\u8BC1\u7801";
    state.errorDetail = "\u8F93\u5165\u6536\u5230\u7684\u9A8C\u8BC1\u7801\u540E\u518D\u786E\u8BA4\u3002";
    render();
    return;
  }
  if (channel !== void 0) verification.channel = channel;
  verification.busy = true;
  clearError();
  render();
  try {
    const result = await portalIdentityVerification(
      verification.organizationCode,
      verification.applicationCode,
      verification.identifier,
      verification.channel,
      code
    );
    if (state.verification !== verification) return;
    rememberRequest(result.requestId);
    if (code === void 0) {
      state.errorTitle = "\u9A8C\u8BC1\u8BF7\u6C42\u5DF2\u53D7\u7406";
      state.errorDetail = "\u5982\u8D26\u53F7\u5DF2\u767B\u8BB0\u6240\u9009\u8054\u7CFB\u65B9\u5F0F\u4E14\u901A\u9053\u53EF\u7528\uFF0C\u60A8\u5C06\u6536\u5230\u9A8C\u8BC1\u7801\u3002\u672A\u6536\u5230\u65F6\u8BF7\u7A0D\u540E\u91CD\u8BD5\u6216\u8054\u7CFB\u5E94\u7528\u7BA1\u7406\u5458\u3002";
    } else {
      state.verification = null;
      state.errorTitle = "\u672C\u6B21\u8054\u7CFB\u65B9\u5F0F\u9A8C\u8BC1\u5DF2\u5B8C\u6210";
      state.errorDetail = "\u8BF7\u91CD\u65B0\u767B\u5F55\uFF1B\u82E5\u5E94\u7528\u8FD8\u8981\u6C42\u5176\u4ED6\u9A8C\u8BC1\uFF0C\u8BF7\u6309\u767B\u5F55\u63D0\u793A\u7EE7\u7EED\u3002";
      render();
    }
  } catch (error) {
    if (state.verification === verification) setError(error);
  } finally {
    verification.busy = false;
    if (state.verification === verification) render();
  }
}
async function submitMfaLogin() {
  if (authBusy || state.pendingMfa === null) return;
  const pending = state.pendingMfa;
  const methodInput = document.getElementById("mfa-login-method");
  const method = methodInput instanceof HTMLSelectElement ? methodInput.value : "";
  if (method !== "totp" && method !== "recovery_code" || !pending.methods.includes(method)) {
    state.errorTitle = "\u8BE5\u9A8C\u8BC1\u65B9\u5F0F\u4E0D\u53EF\u7528";
    state.errorDetail = "\u8BF7\u9009\u62E9\u672C\u8D26\u53F7\u5DF2\u7ECF\u8BBE\u7F6E\u7684\u9A8C\u8BC1\u65B9\u5F0F\u3002";
    render();
    return;
  }
  const attempt = beginAlternativeLogin();
  if (attempt === null) return;
  const current = () => loginCurrent(attempt) && state.pendingMfa === pending;
  clearError();
  try {
    const result = await verifyPortalMfaChallenge(
      attempt.organization,
      attempt.application,
      pending.challengeToken,
      method,
      inputValue("mfa-login-code")
    );
    if (!current()) return;
    rememberRequest(result.requestId);
    state.pendingMfa = null;
    await completePrimaryLogin(result.data, attempt);
  } catch (error) {
    if (!current()) return;
    setError(error);
  } finally {
    finishAlternativeLogin(attempt);
  }
}
async function submitPasskeyMfaLogin() {
  if (authBusy) return;
  if (state.pendingMfa === null || !state.pendingMfa.methods.includes("passkey") || state.pendingMfa.passkeyOptions === null) {
    state.errorTitle = "\u901A\u884C\u5BC6\u94A5\u4E0D\u53EF\u7528";
    state.errorDetail = "\u8BF7\u6539\u7528\u8EAB\u4EFD\u9A8C\u8BC1\u5668\u6216\u6062\u590D\u7801\uFF0C\u6216\u91CD\u65B0\u53D1\u8D77\u767B\u5F55\u3002";
    render();
    return;
  }
  const pending = state.pendingMfa;
  const attempt = beginAlternativeLogin();
  if (attempt === null) return;
  const current = () => loginCurrent(attempt) && state.pendingMfa === pending;
  clearError();
  try {
    const options = parsePasskeyAssertionOptions(pending.passkeyOptions);
    if (options === null) throw new Error("\u901A\u884C\u5BC6\u94A5\u767B\u5F55\u4FE1\u606F\u4E0D\u5B8C\u6574\u3002");
    const assertion = await getPasskeyAssertion(options);
    if (!current()) return;
    const result = await verifyPortalPasskeyChallenge(
      attempt.organization,
      attempt.application,
      pending.challengeToken,
      pending.passkeyOptions,
      assertion
    );
    if (!current()) return;
    rememberRequest(result.requestId);
    state.pendingMfa = null;
    await completePrimaryLogin(result.data, attempt);
  } catch (error) {
    if (!current()) return;
    setError(error);
  } finally {
    finishAlternativeLogin(attempt);
  }
}
async function submitAcceptInvitation() {
  if (acceptingInvitation !== null && invitationCurrent(acceptingInvitation)) return;
  if (!invitationTokenLooksValid(invitationToken)) {
    state.errorTitle = "\u9080\u8BF7\u65E0\u6548";
    state.errorDetail = "\u7F3A\u5C11\u6709\u6548\u9080\u8BF7\u4EE4\u724C\u3002\u8BF7\u4F7F\u7528\u90AE\u4EF6\u6216\u77ED\u4FE1\u4E2D\u7684\u94FE\u63A5\uFF0C\u4E0D\u8981\u624B\u586B token\u3002";
    render();
    return;
  }
  const attempt = {
    token: invitationToken,
    accessToken: state.accessToken,
    organization: state.organizationCode,
    application: state.applicationCode,
    submission: authSubmission
  };
  acceptingInvitation = attempt;
  invitationUsername = inputValue("invite-username");
  invitationDisplayName = inputValue("invite-display-name");
  const password = inputValue("invite-password");
  clearError();
  render();
  try {
    const result = await portalAcceptInvitation(
      attempt.token,
      invitationUsername,
      invitationDisplayName,
      password
    );
    if (!invitationCurrent(attempt)) return;
    rememberRequest(result.requestId);
    invitationToken = "";
    acceptingInvitation = null;
    invitationUsername = "";
    invitationDisplayName = "";
    invitationAcceptedName = result.data.displayName;
    state.accessToken = "";
    state.profile = null;
    state.security = null;
    state.sessions = [];
    state.factors = [];
    state.connections = [];
    state.recoveryCodes = [];
    state.pendingMfa = null;
    state.oauthBound = null;
    pendingTotp = null;
    securityOperation = null;
    recovery = null;
    authSubmission += 1;
    authBusy = false;
    state.errorTitle = "\u5DF2\u4FDD\u5B58";
    state.errorDetail = "\u9080\u8BF7\u5DF2\u63A5\u53D7\uFF0C\u8BF7\u4F7F\u7528\u65B0\u8D26\u53F7\u767B\u5F55\u3002\u672C\u9875\u4E0D\u4F1A\u81EA\u52A8\u53D6\u5F97\u540E\u53F0\u6216\u5E94\u7528\u4F1A\u8BDD\u3002";
    render();
  } catch (error) {
    if (!invitationCurrent(attempt)) return;
    setError(error);
  } finally {
    if (acceptingInvitation === attempt) {
      acceptingInvitation = null;
      if (invitationCurrent(attempt)) render();
      else {
        const button = document.getElementById("accept-invite-btn");
        if (button instanceof HTMLButtonElement) button.disabled = false;
      }
    }
  }
}
async function submitForgot() {
  await submitRecovery(false);
}
async function submitReset() {
  await submitRecovery(true);
}
async function submitRecovery(resetPassword) {
  const draft = recoveryDraft();
  if (draft.busy) return;
  const channelInput = document.getElementById("reset-channel");
  const channel = channelInput instanceof HTMLSelectElement ? channelInput.value : "";
  draft.identifier = inputValue("reset-identifier").trim();
  if (draft.identifier === "" || channel !== "email" && channel !== "phone") {
    state.errorTitle = "\u8BF7\u68C0\u67E5\u627E\u56DE\u4FE1\u606F";
    state.errorDetail = "\u8BF7\u8F93\u5165\u8D26\u53F7\uFF0C\u5E76\u9009\u62E9\u90AE\u7BB1\u6216\u624B\u673A\u63A5\u6536\u9A8C\u8BC1\u7801\u3002";
    render();
    return;
  }
  draft.channel = channel;
  const code = inputValue("reset-code");
  const password = inputValue("reset-password");
  const current = () => recovery === draft && draft.organization === state.organizationCode && draft.application === state.applicationCode;
  draft.busy = true;
  clearError();
  render();
  try {
    const result = resetPassword ? await portalResetPassword(draft.organization, draft.application, draft.identifier, draft.channel, code, password) : await portalForgotPassword(draft.organization, draft.application, draft.identifier, draft.channel);
    if (!current()) return;
    rememberRequest(result.requestId);
    state.errorTitle = resetPassword ? "\u5BC6\u7801\u5DF2\u91CD\u7F6E" : "\u8BF7\u67E5\u770B\u9A8C\u8BC1\u7801";
    state.errorDetail = resetPassword ? "\u6240\u6709\u8BBE\u5907\u9700\u8981\u91CD\u65B0\u767B\u5F55\uFF0C\u8BF7\u4F7F\u7528\u65B0\u5BC6\u7801\u767B\u5F55\u3002" : "\u5982\u8D26\u53F7\u5B58\u5728\uFF0C\u91CD\u7F6E\u9A8C\u8BC1\u7801\u5DF2\u53D1\u9001\u3002";
  } catch (error) {
    if (!current()) return;
    setError(error);
  } finally {
    if (current()) {
      draft.busy = false;
      render();
    }
  }
}
async function loadAll() {
  const submission = authSubmission;
  const accessToken = state.accessToken;
  if (state.accessToken === "") {
    state.errorTitle = "\u8BF7\u5148\u767B\u5F55";
    state.errorDetail = "\u767B\u5F55\u6210\u529F\u540E\u4F1A\u81EA\u52A8\u6253\u5F00\u8D26\u6237\u5B89\u5168\u8BBE\u7F6E\u3002";
    render();
    return;
  }
  clearError();
  try {
    const [profile, security, sessions, factors, connections] = await Promise.all([
      loadPortalProfile(state.accessToken),
      loadPortalSecurity(state.accessToken),
      loadPortalSessions(state.accessToken),
      loadPortalFactors(state.accessToken),
      loadPortalConnections(state.accessToken)
    ]);
    if (submission !== authSubmission || accessToken !== state.accessToken) return;
    rememberRequest(profile.requestId);
    state.profile = profile.data;
    state.security = security.data;
    state.sessions = sessions.data;
    state.factors = factors.data;
    state.connections = connections.data;
  } catch (error) {
    if (submission !== authSubmission || accessToken !== state.accessToken) return;
    setError(error);
    state.profile = null;
    state.security = null;
    state.sessions = [];
    state.factors = [];
    state.connections = [];
  }
  render();
}
async function startTotp() {
  const operation = beginSecurityOperation();
  if (operation === null) return;
  clearError();
  try {
    const result = await startPortalTotp(
      operation.token,
      inputValue("totp-name"),
      inputValue("totp-password")
    );
    if (!securityCurrent(operation)) return;
    rememberRequest(result.requestId);
    state.errorTitle = "\u8BF7\u5B8C\u6210\u9A8C\u8BC1\u5668\u7ED1\u5B9A";
    state.errorDetail = `\u8BF7\u5728\u9A8C\u8BC1\u5668\u4E2D\u6DFB\u52A0\u5BC6\u94A5 ${result.data.secret}\uFF0C\u518D\u8F93\u5165\u516D\u4F4D\u9A8C\u8BC1\u7801\u5B8C\u6210\u786E\u8BA4\u3002`;
    pendingTotp = { ...operation, factorId: result.data.factorId };
  } catch (error) {
    if (!securityCurrent(operation)) return;
    setError(error);
  } finally {
    finishSecurityOperation(operation);
  }
}
async function confirmTotp() {
  if (pendingTotp === null || !securityCurrent(pendingTotp)) {
    state.errorTitle = "\u8BF7\u5148\u5F00\u59CB\u7ED1\u5B9A";
    state.errorDetail = "\u8BF7\u5148\u751F\u6210\u9A8C\u8BC1\u5668\u5BC6\u94A5\uFF0C\u518D\u8F93\u5165\u9A8C\u8BC1\u7801\u3002";
    render();
    return;
  }
  const factorId = pendingTotp.factorId;
  const operation = beginSecurityOperation();
  if (operation === null) return;
  clearError();
  try {
    const result = await confirmPortalTotp(operation.token, factorId, inputValue("totp-code"));
    if (!securityCurrent(operation)) return;
    rememberRequest(result.requestId);
    state.recoveryCodes = result.data;
    pendingTotp = null;
    const factors = await loadPortalFactors(operation.token);
    if (!securityCurrent(operation)) return;
    state.factors = factors.data;
  } catch (error) {
    if (!securityCurrent(operation)) return;
    setError(error);
  } finally {
    finishSecurityOperation(operation);
  }
}
async function regenerateRecoveryCodes() {
  const operation = beginSecurityOperation();
  if (operation === null) return;
  clearError();
  try {
    const result = await regeneratePortalRecoveryCodes(operation.token, inputValue("recovery-password"));
    if (!securityCurrent(operation)) return;
    rememberRequest(result.requestId);
    state.recoveryCodes = result.data;
  } catch (error) {
    if (!securityCurrent(operation)) return;
    setError(error);
  } finally {
    finishSecurityOperation(operation);
  }
}
async function registerPasskey() {
  const operation = beginSecurityOperation();
  if (operation === null) return;
  clearError();
  try {
    const started = await startPortalPasskey(
      operation.token,
      inputValue("passkey-name"),
      inputValue("passkey-password")
    );
    if (!securityCurrent(operation)) return;
    rememberRequest(started.requestId);
    const credential = await createPasskeyCredential(started.data.options);
    if (!securityCurrent(operation)) return;
    const result = await finishPortalPasskey(operation.token, {
      ...credential,
      challenge_token: started.data.challengeToken
    });
    if (!securityCurrent(operation)) return;
    rememberRequest(result.requestId);
    const factors = await loadPortalFactors(operation.token);
    if (!securityCurrent(operation)) return;
    state.factors = factors.data;
    state.errorTitle = "\u901A\u884C\u5BC6\u94A5\u5DF2\u6DFB\u52A0";
    state.errorDetail = "\u4E0B\u6B21\u53EF\u4EE5\u5728\u652F\u6301\u7684\u8BBE\u5907\u4E0A\u4F7F\u7528\u5B83\u767B\u5F55\u3002";
  } catch (error) {
    if (!securityCurrent(operation)) return;
    setError(error);
  } finally {
    finishSecurityOperation(operation);
  }
}
async function saveDisplayName() {
  const operation = beginSecurityOperation();
  if (operation === null) return;
  clearError();
  try {
    const result = await updatePortalProfile(operation.token, inputValue("display-name"));
    if (!securityCurrent(operation)) return;
    rememberRequest(result.requestId);
    state.profile = result.data;
  } catch (error) {
    if (!securityCurrent(operation)) return;
    setError(error);
  } finally {
    finishSecurityOperation(operation);
  }
}
async function revokeSession(sessionId) {
  const currentSession = state.sessions.some((session) => session.id === sessionId && session.current);
  const operation = beginSecurityOperation();
  if (operation === null) return;
  clearError();
  try {
    const result = await revokePortalSession(operation.token, sessionId);
    if (!securityCurrent(operation)) return;
    rememberRequest(result.requestId);
    if (currentSession) {
      clearLocalSession();
      state.errorTitle = "\u5F53\u524D\u4F1A\u8BDD\u5DF2\u64A4\u9500";
      state.errorDetail = "\u8BF7\u91CD\u65B0\u767B\u5F55\u540E\u7EE7\u7EED\u3002";
      render();
      return;
    }
    const sessions = await loadPortalSessions(operation.token);
    if (!securityCurrent(operation)) return;
    state.sessions = sessions.data;
  } catch (error) {
    if (!securityCurrent(operation)) return;
    setError(error);
  } finally {
    finishSecurityOperation(operation);
  }
}
async function revokeFactor(factor) {
  const operation = beginSecurityOperation();
  if (operation === null) return;
  clearError();
  try {
    const result = await revokePortalFactor(
      operation.token,
      factor.id,
      factor.type,
      inputValue("factor-password")
    );
    if (!securityCurrent(operation)) return;
    rememberRequest(result.requestId);
    const factors = await loadPortalFactors(operation.token);
    if (!securityCurrent(operation)) return;
    state.factors = factors.data;
  } catch (error) {
    if (!securityCurrent(operation)) return;
    setError(error);
  } finally {
    finishSecurityOperation(operation);
  }
}
async function changePassword() {
  const operation = beginSecurityOperation();
  if (operation === null) return;
  clearError();
  try {
    const result = await changePortalPassword(
      operation.token,
      inputValue("current-password"),
      inputValue("new-password")
    );
    if (!securityCurrent(operation)) return;
    rememberRequest(result.requestId);
    clearLocalSession();
    state.errorTitle = "\u5DF2\u4FDD\u5B58";
    state.errorDetail = "\u5BC6\u7801\u5DF2\u4FEE\u6539\uFF0C\u6240\u6709\u8BBE\u5907\u9700\u8981\u91CD\u65B0\u767B\u5F55\u3002\u5F53\u524D\u767B\u5F55\u72B6\u6001\u5DF2\u5931\u6548\u3002";
    render();
  } catch (error) {
    if (!securityCurrent(operation)) return;
    setError(error);
  } finally {
    finishSecurityOperation(operation);
  }
}
function clearLocalSession() {
  state.accessToken = "";
  state.profile = null;
  state.security = null;
  state.sessions = [];
  state.factors = [];
  state.connections = [];
  state.pendingMfa = null;
  state.oauthBound = null;
  state.recoveryCodes = [];
  state.verification = null;
  pendingTotp = null;
  securityOperation = null;
  recovery = null;
  authSubmission += 1;
  authBusy = false;
}
async function submitLogout() {
  const operation = beginSecurityOperation();
  if (operation === null) return;
  clearError();
  try {
    const result = await logoutPortalSession(operation.token);
    if (!securityCurrent(operation)) return;
    rememberRequest(result.requestId);
    clearLocalSession();
    state.errorTitle = "\u5DF2\u9000\u51FA\u767B\u5F55";
    state.errorDetail = "\u5F53\u524D\u4F1A\u8BDD\u5DF2\u7ED3\u675F\u3002";
    render();
  } catch (error) {
    if (!securityCurrent(operation)) return;
    setError(error);
  } finally {
    finishSecurityOperation(operation);
  }
}
function escapeHtml(value) {
  return value.replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;").replaceAll('"', "&quot;");
}
function render() {
  clearCaptchas();
  const brand = state.experience?.brandName ?? state.profile?.applicationName ?? "\u5F53\u524D\u5E94\u7528";
  if (state.experience !== null) {
    document.documentElement.style.setProperty(
      "--brand",
      state.experience.primaryColor
    );
    document.documentElement.style.colorScheme = state.experience.themeMode === "dark" ? "dark" : state.experience.themeMode === "light" ? "light" : "light dark";
  }
  document.title = `${brand} \xB7 \u8D26\u6237\u5B89\u5168`;
  const logo = state.experience?.logoUrl.trim() ?? "";
  const termsUrl = state.experience?.termsUrl.trim() ?? "";
  const privacyUrl = state.experience?.privacyUrl.trim() ?? "";
  root().innerHTML = `
    <main class="panel">
      <header class="portal-heading">
        ${logo === "" ? "" : `<img class="brand-logo" src="${escapeHtml(logo)}" alt="${escapeHtml(brand)}" />`}
        <h1>${escapeHtml(brand)}</h1>
      </header>
      <p class="hint">\u8FD9\u662F\u60A8\u7684\u8D26\u6237\u4E0E\u767B\u5F55\u5B89\u5168\u8BBE\u7F6E\u3002\u8BBF\u95EE\u4EE4\u724C\u53EA\u5728\u672C\u6B21\u9875\u9762\u4F7F\u7528\uFF0C\u4E0D\u4F1A\u8FDB\u5165 SandAdmin \u540E\u53F0\uFF0C\u4E5F\u4E0D\u4F1A\u4FDD\u5B58\u5728\u6D4F\u89C8\u5668\u4E2D\uFF1B\u5916\u90E8\u767B\u5F55\u4EC5\u5728\u5F53\u524D\u6807\u7B7E\u9875\u4E34\u65F6\u4FDD\u7559\u4E00\u6B21\u786E\u8BA4\u4FE1\u606F\u3002</p>
      ${state.accessToken === "" ? "" : '<button type="button" id="logout-btn">\u9000\u51FA\u767B\u5F55</button>'}
      ${state.usingDefaultExperience ? '<p class="hint">\u8BE5\u5E94\u7528\u5C1A\u672A\u8BBE\u7F6E\u767B\u5F55\u5916\u89C2\uFF0C\u6B63\u5728\u4F7F\u7528\u57FA\u7840\u5BC6\u7801\u767B\u5F55\u3002\u6CE8\u518C\u9ED8\u8BA4\u5173\u95ED\u3002</p>' : ""}
      ${state.errorTitle === "" ? "" : `<section class="alert"><strong>${escapeHtml(state.errorTitle)}</strong><p>${escapeHtml(state.errorDetail)}</p></section>`}
      ${renderOAuth()}
      ${renderCas()}
      ${renderExperience()}
      ${renderInvitation()}
      ${renderLogin()}
      ${renderMfaLogin()}
      ${renderProfile()}
      ${renderSecurity()}
      ${renderSessions()}
      ${renderFactors()}
      ${renderConnections()}
      ${renderPassword()}
      ${termsUrl === "" && privacyUrl === "" ? "" : `<footer class="portal-links">${termsUrl === "" ? "" : `<a href="${escapeHtml(termsUrl)}" target="_blank" rel="noreferrer">\u670D\u52A1\u534F\u8BAE</a>`}${termsUrl !== "" && privacyUrl !== "" ? " \xB7 " : ""}${privacyUrl === "" ? "" : `<a href="${escapeHtml(privacyUrl)}" target="_blank" rel="noreferrer">\u9690\u79C1\u653F\u7B56</a>`}</footer>`}
    </main>
  `;
  updateDecisionButtons();
  document.getElementById("logout-btn")?.addEventListener("click", () => {
    void submitLogout();
  });
  void mountCaptcha("login");
  void mountCaptcha("register");
  document.getElementById("load-cas")?.addEventListener("click", () => {
    void loadCas();
  });
  document.getElementById("confirm-cas")?.addEventListener("click", () => {
    void submitCasConfirm();
  });
  document.getElementById("reject-cas")?.addEventListener("click", () => {
    void submitCasReject();
  });
  document.getElementById("approve-oauth")?.addEventListener("click", () => {
    void submitOAuthDecision("approve");
  });
  document.getElementById("deny-oauth")?.addEventListener("click", () => {
    void submitOAuthDecision("deny");
  });
  document.getElementById("load-experience")?.addEventListener("click", () => {
    void loadExperience();
  });
  document.getElementById("accept-invite-btn")?.addEventListener("click", () => {
    void submitAcceptInvitation();
  });
  document.getElementById("login-btn")?.addEventListener("click", () => {
    void submitLogin();
  });
  document.getElementById("passkey-login-btn")?.addEventListener("click", () => {
    void submitPasskeyLogin();
  });
  document.getElementById("mfa-login-btn")?.addEventListener("click", () => {
    void submitMfaLogin();
  });
  document.getElementById("mfa-passkey-btn")?.addEventListener("click", () => {
    void submitPasskeyMfaLogin();
  });
  document.getElementById("register-btn")?.addEventListener("click", () => {
    void submitRegister();
  });
  document.getElementById("verification-email")?.addEventListener("click", () => {
    void submitVerification("email");
  });
  document.getElementById("verification-phone")?.addEventListener("click", () => {
    void submitVerification("phone");
  });
  document.getElementById("verification-confirm")?.addEventListener("click", () => {
    void submitVerification();
  });
  document.getElementById("verification-back")?.addEventListener("click", () => {
    state.verification = null;
    clearError();
    render();
  });
  document.getElementById("forgot-btn")?.addEventListener("click", () => {
    void submitForgot();
  });
  document.getElementById("reset-btn")?.addEventListener("click", () => {
    void submitReset();
  });
  document.getElementById("save-name")?.addEventListener("click", () => {
    void saveDisplayName();
  });
  document.getElementById("change-password")?.addEventListener("click", () => {
    void changePassword();
  });
  document.getElementById("start-totp")?.addEventListener("click", () => {
    void startTotp();
  });
  document.getElementById("confirm-totp")?.addEventListener("click", () => {
    void confirmTotp();
  });
  document.getElementById("regenerate-recovery")?.addEventListener("click", () => {
    void regenerateRecoveryCodes();
  });
  document.getElementById("register-passkey")?.addEventListener("click", () => {
    void registerPasskey();
  });
  for (const button of Array.from(root().querySelectorAll("[data-external-login]"))) {
    button.addEventListener("click", () => {
      const protocol = button.getAttribute("data-external-protocol");
      const providerCode = button.getAttribute("data-external-provider");
      if (providerCode !== null && (protocol === "oidc" || protocol === "oauth2" || protocol === "saml")) {
        void startExternalLogin(protocol, providerCode);
      }
    });
  }
  for (const button of Array.from(root().querySelectorAll("[data-revoke-session]"))) {
    button.addEventListener("click", () => {
      const id = Number(button.getAttribute("data-revoke-session"));
      if (Number.isInteger(id) && id > 0) void revokeSession(id);
    });
  }
  for (const button of Array.from(root().querySelectorAll("[data-revoke-factor]"))) {
    button.addEventListener("click", () => {
      const id = Number(button.getAttribute("data-revoke-factor"));
      const type = button.getAttribute("data-factor-type");
      const factor = state.factors.find((item) => item.id === id);
      if (factor !== void 0 && (type === "totp" || type === "passkey")) {
        void revokeFactor(factor);
      }
    });
  }
}
function renderOAuth() {
  if (oauthRequest === "") return "";
  if (state.oauth === null) {
    return `<section><h2>\u5E94\u7528\u6388\u6743</h2><p class="empty">\u6B63\u5728\u8BFB\u53D6\u6388\u6743\u8BF7\u6C42\u3002\u8BF7\u4E0D\u8981\u624B\u52A8\u590D\u5236\u6216\u4FEE\u6539\u94FE\u63A5\u3002</p></section>`;
  }
  const scopes = state.oauth.scopes.length === 0 ? "\u57FA\u672C\u767B\u5F55\u4FE1\u606F" : state.oauth.scopes.map(escapeHtml).join("\u3001");
  if (state.oauthBound === null) {
    return `
      <section>
        <h2>\u5E94\u7528\u6388\u6743</h2>
        <p><strong>${escapeHtml(state.oauth.clientName)}</strong> \u5E0C\u671B\u4F7F\u7528\u60A8\u7684\u8D26\u6237\u767B\u5F55\u3002</p>
        <p>\u9700\u8981\u8BBF\u95EE\uFF1A${scopes}</p>
        <p class="hint">\u8BF7\u5728\u4E0B\u65B9\u4F7F\u7528\u6B64\u5E94\u7528\u7684\u8D26\u53F7\u767B\u5F55\u3002\u6388\u6743\u8BF7\u6C42\u5C06\u5728 ${String(state.oauth.expiresIn)} \u79D2\u540E\u5931\u6548\u3002</p>
      </section>
    `;
  }
  return `
    <section>
      <h2>\u786E\u8BA4\u6388\u6743</h2>
      <p><strong>${escapeHtml(state.oauthBound.clientName)}</strong> \u5C06\u83B7\u5F97\uFF1A${state.oauthBound.scopes.length === 0 ? "\u57FA\u672C\u767B\u5F55\u4FE1\u606F" : state.oauthBound.scopes.map(escapeHtml).join("\u3001")}\u3002</p>
      <p class="hint">${state.oauthBound.consentRequired ? "\u8FD9\u662F\u65B0\u7684\u6388\u6743\u8BF7\u6C42\uFF0C\u8BF7\u786E\u8BA4\u540E\u7EE7\u7EED\u3002" : "\u60A8\u5DF2\u7ECF\u6388\u6743\u8FC7\u8FD9\u4E9B\u8303\u56F4\uFF0C\u4ECD\u53EF\u9009\u62E9\u7EE7\u7EED\u6216\u62D2\u7EDD\u3002"}</p>
      <button type="button" id="approve-oauth">\u786E\u8BA4\u5E76\u7EE7\u7EED</button>
      <button type="button" id="deny-oauth">\u62D2\u7EDD</button>
    </section>
  `;
}
function renderCas() {
  if (casRequest === "") {
    return `<section><h2>CAS \u786E\u8BA4</h2><p class="empty">\u6CA1\u6709\u786E\u8BA4\u8BF7\u6C42\u3002\u8BF7\u4ECE\u5DF2\u767B\u8BB0\u7684 CAS \u670D\u52A1\u53D1\u8D77\u767B\u5F55\uFF1B\u7BA1\u7406\u7AEF\u767B\u5F55\u4E0D\u80FD\u4EE3\u66FF\u5E94\u7528\u7528\u6237\u786E\u8BA4\u3002</p></section>`;
  }
  if (state.cas === null) {
    return `
      <section>
        <h2>CAS \u786E\u8BA4</h2>
        <p class="hint">\u5DF2\u4ECE\u94FE\u63A5\u8BFB\u53D6\u786E\u8BA4\u8BF7\u6C42\uFF0C\u9875\u9762\u4E0D\u4F1A\u56DE\u663E request \u6216 Ticket\u3002</p>
        <button type="button" id="load-cas">\u8BFB\u53D6\u76EE\u6807\u670D\u52A1</button>
      </section>
    `;
  }
  return `
    <section>
      <h2>CAS \u786E\u8BA4</h2>
      <p>\u76EE\u6807\u5E94\u7528\uFF1A${escapeHtml(state.cas.applicationName)}</p>
      <p>\u76EE\u6807\u670D\u52A1\uFF1A${escapeHtml(state.cas.serviceName)}</p>
      <p>\u7CBE\u786E\u5730\u5740\uFF1A${escapeHtml(state.cas.serviceUrl)}</p>
      <p class="hint">\u5269\u4F59 ${String(state.cas.expiresIn)} \u79D2\u3002\u8BF7\u5148\u7528\u8BE5\u5E94\u7528\u8D26\u53F7\u767B\u5F55\uFF0C\u518D\u786E\u8BA4\u7EE7\u7EED\u3002</p>
      <button type="button" id="confirm-cas">\u786E\u8BA4\u5E76\u7EE7\u7EED</button>
      <button type="button" id="reject-cas">\u62D2\u7EDD\u5E76\u8FD4\u56DE\u5E94\u7528</button>
    </section>
  `;
}
function renderInvitation() {
  if (invitationAcceptedName !== "") {
    return `<section><h2>\u63A5\u53D7\u9080\u8BF7</h2><p>\u8D26\u53F7\u300C${escapeHtml(invitationAcceptedName)}\u300D\u5DF2\u6FC0\u6D3B\u3002\u9080\u8BF7\u5DF2\u63A5\u53D7\uFF0C\u8BF7\u4F7F\u7528\u65B0\u8D26\u53F7\u767B\u5F55\u3002</p></section>`;
  }
  if (invitationToken === "") {
    return `<section><h2>\u63A5\u53D7\u9080\u8BF7</h2><p class="empty">\u6CA1\u6709\u9080\u8BF7\u4EE4\u724C\u3002\u8BF7\u4F7F\u7528\u90AE\u4EF6\u6216\u77ED\u4FE1\u4E2D\u7684\u94FE\u63A5\uFF1B\u64A4\u9500\u3001\u8FC7\u671F\u548C\u5DF2\u63A5\u53D7\u7684\u94FE\u63A5\u4E0D\u80FD\u91CD\u653E\u3002</p></section>`;
  }
  const disabled = acceptingInvitation !== null && invitationCurrent(acceptingInvitation) ? "disabled" : "";
  return `
    <section>
      <h2>\u63A5\u53D7\u9080\u8BF7</h2>
      <p class="hint">\u5DF2\u4ECE\u9080\u8BF7\u94FE\u63A5\u8BFB\u53D6\u4EE4\u724C\uFF0C\u9875\u9762\u4E0D\u4F1A\u56DE\u663E\u6216\u7F13\u5B58 token\u3002</p>
      <label>\u7528\u6237\u540D<input id="invite-username" value="${escapeHtml(invitationUsername)}" autocomplete="username" ${disabled} /></label>
      <label>\u663E\u793A\u540D\u79F0<input id="invite-display-name" value="${escapeHtml(invitationDisplayName)}" ${disabled} /></label>
      <label>\u5BC6\u7801<input id="invite-password" type="password" autocomplete="new-password" ${disabled} /></label>
      <button type="button" id="accept-invite-btn" ${disabled}>\u63A5\u53D7\u9080\u8BF7\u5E76\u53BB\u767B\u5F55</button>
    </section>
  `;
}
function renderExperience() {
  return `
    <section>
      <h2>\u9009\u62E9\u8981\u767B\u5F55\u7684\u5E94\u7528</h2>
      <label>\u5BA2\u6237\u4E3B\u4F53\u4EE3\u7801<input id="organization-code" value="${escapeHtml(state.organizationCode)}" /></label>
      <label>\u63A5\u5165\u5E94\u7528\u4EE3\u7801<input id="application-code" value="${escapeHtml(state.applicationCode)}" /></label>
      <button type="button" id="load-experience">\u7EE7\u7EED</button>
      ${state.experience === null ? `<p class="empty">\u8BF7\u8F93\u5165\u7BA1\u7406\u5458\u63D0\u4F9B\u7684\u5BA2\u6237\u4E3B\u4F53\u4EE3\u7801\u548C\u5E94\u7528\u4EE3\u7801\u3002</p>` : `<p>\u54C1\u724C\uFF1A${escapeHtml(state.experience.brandName)} \xB7 \u6CE8\u518C\uFF1A${state.experience.registrationMode === "open" ? "\u5F00\u653E\u6CE8\u518C" : state.experience.registrationMode === "invite" ? "\u9080\u8BF7\u6CE8\u518C" : "\u5173\u95ED\u6CE8\u518C"} \xB7 \u767B\u5F55\u65B9\u5F0F\uFF1A${escapeHtml(state.experience.loginMethods.join("\u3001") || "\u65E0")}</p>`}
    </section>
  `;
}
function renderLogin() {
  if (state.experience === null) return "";
  if (state.verification !== null) {
    const verification = state.verification;
    const disabled = verification.busy ? "disabled" : "";
    return `<section>
      <h2>\u9A8C\u8BC1\u8D26\u53F7\u8054\u7CFB\u65B9\u5F0F</h2>
      <p>\u5F53\u524D\u8D26\u53F7\uFF1A${escapeHtml(verification.identifier)}</p>
      <p class="hint">\u9A8C\u8BC1\u7801\u53D1\u9001\u5230\u8BE5\u8D26\u53F7\u5DF2\u767B\u8BB0\u7684\u8054\u7CFB\u65B9\u5F0F\u3002\u8BF7\u9009\u62E9\u9700\u8981\u9A8C\u8BC1\u7684\u90AE\u7BB1\u6216\u624B\u673A\u3002</p>
      <button type="button" id="verification-email" ${disabled}>\u53D1\u9001\u90AE\u7BB1\u9A8C\u8BC1\u7801</button>
      <button type="button" id="verification-phone" ${disabled}>\u53D1\u9001\u624B\u673A\u9A8C\u8BC1\u7801</button>
      <label>${verification.channel === "email" ? "\u90AE\u7BB1" : "\u624B\u673A"}\u9A8C\u8BC1\u7801
        <input id="verification-code" autocomplete="one-time-code" ${disabled} />
      </label>
      <button type="button" id="verification-confirm" ${disabled}>\u786E\u8BA4\u9A8C\u8BC1</button>
      <button type="button" id="verification-back" ${disabled}>\u8FD4\u56DE\u767B\u5F55</button>
    </section>`;
  }
  const passwordEnabled = experienceAllowsPassword(state.experience);
  const passkeyEnabled = experienceAllowsPasskey(state.experience);
  const externalMethods = externalLoginMethods(state.experience);
  const registerEnabled = experienceAllowsRegister(state.experience);
  const recoveryForm = recoveryDraft();
  const recoveryDisabled = recoveryForm.busy ? "disabled" : "";
  const extraFields = state.experience.registrationFields.filter((field) => field !== "username").map(
    (field) => `<label>${registrationFieldLabel(field)}<input id="register-${field}" /></label>`
  ).join("");
  return `
    <section>
      <h2>\u767B\u5F55</h2>
      ${passwordEnabled ? `<label>\u7528\u6237\u540D/\u90AE\u7BB1/\u624B\u673A<input id="login-identifier" /></label>
             <label>\u5BC6\u7801<input id="login-password" type="password" autocomplete="current-password" /></label>
             ${renderCaptcha("login")}
             <button type="button" id="login-btn" disabled>\u767B\u5F55</button>` : `<p class="empty">\u5BC6\u7801\u767B\u5F55\u5DF2\u5173\u95ED\uFF0C\u672C\u9875\u4E0D\u5C55\u793A\u767B\u5F55\u8868\u5355\u3002</p>`}
      ${passkeyEnabled ? '<button type="button" id="passkey-login-btn">\u4F7F\u7528\u901A\u884C\u5BC6\u94A5\u767B\u5F55</button>' : ""}
      ${externalMethods.map((method, index) => `<button type="button" data-external-login data-external-protocol="${method.protocol}" data-external-provider="${escapeHtml(method.providerCode)}">\u4F7F\u7528\u5916\u90E8\u8EAB\u4EFD\u6E90 ${String(index + 1)} \u767B\u5F55</button>`).join("")}
    </section>
    <section>
      <h2>\u6CE8\u518C</h2>
      ${registerEnabled ? `<label>\u7528\u6237\u540D<input id="register-username" /></label>
             <label>\u5BC6\u7801<input id="register-password" type="password" autocomplete="new-password" /></label>
             ${extraFields}
             ${renderCaptcha("register")}
             <button type="button" id="register-btn" disabled>\u6CE8\u518C</button>` : `<p class="empty">${state.experience.registrationMode === "invite" ? "\u5F53\u524D\u4EC5\u9080\u8BF7\u6CE8\u518C\uFF0C\u8BF7\u4F7F\u7528\u9080\u8BF7\u94FE\u63A5\u3002" : "\u6CE8\u518C\u5DF2\u5173\u95ED\u3002"}</p>`}
    </section>
    ${passwordEnabled ? `<section>
             <h2>\u627E\u56DE\u5BC6\u7801</h2>
             <label>\u8D26\u53F7\u6807\u8BC6<input id="reset-identifier" value="${escapeHtml(recoveryForm.identifier)}" ${recoveryDisabled} /></label>
             <label>\u63A5\u6536\u65B9\u5F0F<select id="reset-channel" ${recoveryDisabled}>
               <option value="email" ${recoveryForm.channel === "email" ? "selected" : ""}>\u90AE\u7BB1</option>
               <option value="phone" ${recoveryForm.channel === "phone" ? "selected" : ""}>\u624B\u673A</option>
             </select></label>
             <button type="button" id="forgot-btn" ${recoveryDisabled}>\u53D1\u9001\u91CD\u7F6E\u9A8C\u8BC1\u7801</button>
             <label>\u9A8C\u8BC1\u7801<input id="reset-code" autocomplete="one-time-code" ${recoveryDisabled} /></label>
             <label>\u65B0\u5BC6\u7801<input id="reset-password" type="password" autocomplete="new-password" ${recoveryDisabled} /></label>
             <button type="button" id="reset-btn" ${recoveryDisabled}>\u91CD\u7F6E\u5BC6\u7801</button>
           </section>` : ""}
  `;
}
function renderMfaLogin() {
  if (state.pendingMfa === null) return "";
  const supportsTotp = state.pendingMfa.methods.includes("totp");
  const supportsRecovery = state.pendingMfa.methods.includes("recovery_code");
  const supportsPasskey = state.pendingMfa.methods.includes("passkey") && state.pendingMfa.passkeyOptions !== null;
  return `
    <section>
      <h2>\u5B89\u5168\u9A8C\u8BC1</h2>
      <p class="hint">\u8BF7\u8F93\u5165\u9A8C\u8BC1\u5668\u4E2D\u7684\u516D\u7801\uFF0C\u6216\u4F7F\u7528\u4E00\u7EC4\u5C1A\u672A\u4F7F\u7528\u7684\u6062\u590D\u7801\u3002\u6B64\u6B21\u8BF7\u6C42\u53EA\u53EF\u4F7F\u7528\u4E00\u6B21\u3002</p>
      <label>\u9A8C\u8BC1\u65B9\u5F0F
        <select id="mfa-login-method">
          ${supportsTotp ? '<option value="totp">\u8EAB\u4EFD\u9A8C\u8BC1\u5668</option>' : ""}
          ${supportsRecovery ? '<option value="recovery_code">\u6062\u590D\u7801</option>' : ""}
        </select>
      </label>
      <label>\u9A8C\u8BC1\u7801\u6216\u6062\u590D\u7801<input id="mfa-login-code" autocomplete="one-time-code" /></label>
      <button type="button" id="mfa-login-btn">\u9A8C\u8BC1\u5E76\u767B\u5F55</button>
      ${supportsPasskey ? '<button type="button" id="mfa-passkey-btn">\u4F7F\u7528\u901A\u884C\u5BC6\u94A5\u767B\u5F55</button>' : ""}
    </section>
  `;
}
function renderProfile() {
  if (state.profile === null) {
    return `<section><h2>\u4E2A\u4EBA\u8D44\u6599</h2><p class="empty">\u5C1A\u672A\u52A0\u8F7D\u3002\u8FC7\u671F token \u4E0E\u6CA1\u6709\u6570\u636E\u4F1A\u5206\u5F00\u63D0\u793A\u3002</p></section>`;
  }
  return `
    <section>
      <h2>\u4E2A\u4EBA\u8D44\u6599</h2>
      <p>\u6240\u5C5E\u4EA7\u54C1\uFF1A${escapeHtml(state.profile.applicationName)}</p>
      <p>\u6240\u5C5E\u5BA2\u6237\u4E3B\u4F53\uFF1A${escapeHtml(state.profile.organizationName)}</p>
      <label>\u663E\u793A\u540D\u79F0<input id="display-name" value="${escapeHtml(state.profile.displayName)}" /></label>
      <button type="button" id="save-name">\u4FDD\u5B58\u663E\u793A\u540D\u79F0</button>
    </section>
  `;
}
function renderSecurity() {
  if (state.security === null) {
    return `<section><h2>\u5B89\u5168\u6982\u51B5</h2><p class="empty">\u5C1A\u672A\u52A0\u8F7D\u3002</p></section>`;
  }
  return `
    <section>
      <h2>\u5B89\u5168\u6982\u51B5</h2>
      <p>\u672C\u5730\u5BC6\u7801\uFF1A${state.security.passwordEnabled ? "\u5DF2\u542F\u7528" : "\u672A\u914D\u7F6E"}</p>
      <p>\u6709\u6548\u4F1A\u8BDD\uFF1A${String(state.security.activeSessions)}</p>
      <p>\u9A8C\u8BC1\u5668\uFF1A${String(state.security.totpFactors)}</p>
      <p>\u901A\u884C\u5BC6\u94A5\uFF1A${String(state.security.passkeys)}</p>
      <p>\u5916\u90E8\u8D26\u53F7\uFF1A${String(state.security.connectedAccounts)}</p>
    </section>
  `;
}
function renderSessions() {
  if (state.sessions.length === 0) {
    return `<section><h2>\u4F1A\u8BDD</h2><p class="empty">\u5F53\u524D\u6CA1\u6709\u6709\u6548\u4F1A\u8BDD\u3002\u8FD9\u4E0E\u6CA1\u6709\u6743\u9650\u4E0D\u540C\u3002</p></section>`;
  }
  const items = state.sessions.map(
    (session) => `
        <li>
          ${session.current ? "\u5F53\u524D\u4F1A\u8BDD" : "\u5176\u4ED6\u8BBE\u5907"} \xB7 \u6700\u8FD1\u4F7F\u7528 ${escapeHtml(session.lastUsedTime)}
          <button type="button" data-revoke-session="${String(session.id)}">\u64A4\u9500</button>
        </li>
      `
  ).join("");
  return `<section><h2>\u4F1A\u8BDD</h2><ul>${items}</ul></section>`;
}
function renderFactors() {
  const list = state.factors.length === 0 ? `<p class="empty">\u8FD8\u6CA1\u6709\u9A8C\u8BC1\u5668\u6216\u901A\u884C\u5BC6\u94A5\u3002\u6DFB\u52A0\u901A\u884C\u5BC6\u94A5\u4ECD\u8D70\u8BA4\u8BC1\u5668\u6D41\u7A0B\u3002</p>` : `<ul>${state.factors.map(
    (factor) => `
              <li>
                ${factor.type === "passkey" ? "\u901A\u884C\u5BC6\u94A5" : "\u9A8C\u8BC1\u5668"} \xB7 ${escapeHtml(factor.name)} \xB7 ${factor.status === 1 ? "\u6B63\u5E38" : "\u5DF2\u505C\u7528"}
                <button type="button" data-revoke-factor="${String(factor.id)}" data-factor-type="${factor.type}">\u64A4\u9500</button>
              </li>
            `
  ).join("")}</ul>`;
  return `
    <section>
      <h2>\u767B\u5F55\u65B9\u5F0F</h2>
      <p class="hint">\u6DFB\u52A0\u3001\u79FB\u9664\u6216\u91CD\u65B0\u751F\u6210\u6062\u590D\u7801\u65F6\uFF0C\u9700\u8981\u518D\u6B21\u786E\u8BA4\u5F53\u524D\u5BC6\u7801\u3002</p>
      <label>\u5F53\u524D\u5BC6\u7801<input id="factor-password" type="password" autocomplete="current-password" /></label>
      ${list}
      <h3>\u6DFB\u52A0\u9A8C\u8BC1\u5668</h3>
      <label>\u8BBE\u5907\u540D\u79F0<input id="totp-name" value="\u8EAB\u4EFD\u9A8C\u8BC1\u5668" /></label>
      <label>\u5F53\u524D\u5BC6\u7801<input id="totp-password" type="password" autocomplete="current-password" /></label>
      <button type="button" id="start-totp">\u751F\u6210\u9A8C\u8BC1\u5668\u5BC6\u94A5</button>
      <label>\u516D\u4F4D\u9A8C\u8BC1\u7801<input id="totp-code" inputmode="numeric" autocomplete="one-time-code" /></label>
      <button type="button" id="confirm-totp">\u786E\u8BA4\u7ED1\u5B9A</button>
      <h3>\u6DFB\u52A0\u901A\u884C\u5BC6\u94A5</h3>
      <label>\u8BBE\u5907\u540D\u79F0<input id="passkey-name" value="\u6B64\u8BBE\u5907" /></label>
      <label>\u5F53\u524D\u5BC6\u7801<input id="passkey-password" type="password" autocomplete="current-password" /></label>
      <button type="button" id="register-passkey">\u6DFB\u52A0\u901A\u884C\u5BC6\u94A5</button>
      <h3>\u6062\u590D\u7801</h3>
      <label>\u5F53\u524D\u5BC6\u7801<input id="recovery-password" type="password" autocomplete="current-password" /></label>
      <button type="button" id="regenerate-recovery">\u751F\u6210\u65B0\u7684\u6062\u590D\u7801</button>
      ${state.recoveryCodes.length === 0 ? "" : `<p class="alert"><strong>\u8BF7\u7ACB\u5373\u6284\u5199\u6062\u590D\u7801</strong><br>${state.recoveryCodes.map(escapeHtml).join("<br>")}</p>`}
    </section>
  `;
}
function renderConnections() {
  if (state.connections.length === 0) {
    return `<section><h2>\u5916\u90E8\u8FDE\u63A5</h2><p class="empty">\u5F53\u524D\u6CA1\u6709\u53EF\u7528\u7684\u5916\u90E8\u8D26\u53F7\u8FDE\u63A5\u3002</p></section>`;
  }
  const items = state.connections.map((item) => {
    const available = connectionAvailable(item.sourceState);
    return `<li>${escapeHtml(item.providerName)} \xB7 ${escapeHtml(item.accountHint)} \xB7 ${available ? "\u53EF\u7528" : "\u4E0D\u53EF\u7528\uFF08\u5DF2\u505C\u7528\u6216\u5DF2\u5378\u8F7D\uFF09"}</li>`;
  }).join("");
  return `<section><h2>\u5916\u90E8\u8FDE\u63A5</h2><ul>${items}</ul></section>`;
}
function renderPassword() {
  return `
    <section>
      <h2>\u4FEE\u6539\u5BC6\u7801</h2>
      <p class="hint">\u6210\u529F\u540E\u5168\u90E8\u8BBE\u5907\u90FD\u9700\u91CD\u65B0\u767B\u5F55\uFF0C\u5F53\u524D\u4EE4\u724C\u7ACB\u5373\u5931\u6548\u3002</p>
      <label>\u5F53\u524D\u5BC6\u7801<input id="current-password" type="password" autocomplete="current-password" /></label>
      <label>\u65B0\u5BC6\u7801<input id="new-password" type="password" autocomplete="new-password" /></label>
      <button type="button" id="change-password">\u4FEE\u6539\u5BC6\u7801</button>
    </section>
  `;
}
render();
if (federationCallback !== null) {
  void completeFederationCallback();
}
if (federationCallback === null && oauthRequestLooksValid(oauthRequest)) {
  void loadOAuth();
}
if (federationCallback === null && casRequestLooksValid(casRequest)) {
  void loadCas();
}
if (federationCallback === null && oauthRequest === "" && casRequest === "" && state.organizationCode !== "" && state.applicationCode !== "") {
  void loadExperience();
}
