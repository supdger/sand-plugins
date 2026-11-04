export interface CaptchaConfiguration {
  readonly kind: "turnstile";
  readonly site_key: string;
  readonly action: "login" | "register";
  readonly application_binding: string;
}

export type CaptchaStatus = "loading" | "waiting" | "verified" | "expired" | "error" | "destroyed";

export function parseCaptchaConfiguration(value: unknown): CaptchaConfiguration | null {
  if (typeof value !== "object" || value === null) return null;
  if (!("kind" in value) || value.kind !== "turnstile"
    || !("site_key" in value) || typeof value.site_key !== "string"
    || !/^[a-zA-Z0-9_-]{1,255}$/.test(value.site_key)
    || !("action" in value) || (value.action !== "login" && value.action !== "register")
    || !("application_binding" in value) || typeof value.application_binding !== "string"
    || !/^[a-zA-Z0-9_-]{1,255}$/.test(value.application_binding)) return null;
  return { kind: value.kind, site_key: value.site_key, action: value.action,
    application_binding: value.application_binding };
}

interface TurnstileOptions {
  sitekey: string;
  action: string;
  cData: string;
  "response-field": false;
  retry: "never";
  "refresh-expired": "manual";
  "refresh-timeout": "manual";
  callback: (token: unknown) => void;
  "expired-callback": () => void;
  "error-callback": () => void;
  "timeout-callback": () => void;
  "unsupported-callback": () => void;
}

interface TurnstileSdk {
  ready(callback: () => void): void;
  render(container: HTMLElement, options: TurnstileOptions): unknown;
  remove(id: string): void;
}

function readSdk(): TurnstileSdk | null {
  const value: unknown = Reflect.get(globalThis, "turnstile");
  if (typeof value !== "object" || value === null
    || !("ready" in value) || typeof value.ready !== "function"
    || !("render" in value) || typeof value.render !== "function"
    || !("remove" in value) || typeof value.remove !== "function") return null;
  const { ready, render, remove } = value;
  return {
    ready: (callback) => { ready.call(value, callback); },
    render: (container, options): unknown => render.call(value, container, options),
    remove: (id) => { remove.call(value, id); },
  };
}

const SCRIPT_URL = "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";
let pendingSdk: Promise<TurnstileSdk> | null = null;

function loadSdk(): Promise<TurnstileSdk> {
  if (pendingSdk !== null) return pendingSdk;
  const attempt = new Promise<TurnstileSdk>((resolve, reject) => {
    let script: HTMLScriptElement | null = null;
    let settled = false;
    const finish = (sdk: TurnstileSdk | null): void => {
      if (settled) return;
      settled = true;
      clearTimeout(timer);
      if (script !== null) { script.onload = null; script.onerror = null; }
      if (sdk !== null) resolve(sdk);
      else { script?.remove(); reject(new Error("人机验证加载失败，请重试。")); }
    };
    const timer = setTimeout(() => finish(null), 15_000);
    const ready = (): void => {
      const sdk = readSdk();
      if (sdk === null) { finish(null); return; }
      try { sdk.ready(() => finish(sdk)); } catch { finish(null); }
    };
    if (readSdk() !== null) { ready(); return; }
    script = document.createElement("script");
    script.src = SCRIPT_URL;
    script.async = true;
    script.defer = true;
    script.onload = ready;
    script.onerror = () => finish(null);
    try { document.head.append(script); } catch { finish(null); }
  });
  pendingSdk = attempt;
  void attempt.catch(() => { if (pendingSdk === attempt) pendingSdk = null; });
  return attempt;
}

/** Mount after the container exists; destroy before changing application or replacing its DOM. */
export class CaptchaWidget {
  private generation = 0;
  private token = "";
  private validUntil = 0;
  private sdk: TurnstileSdk | null = null;
  private widgetId: string | null = null;
  private status: CaptchaStatus = "destroyed";
  private readonly container: HTMLElement;
  private readonly onChange: (status: CaptchaStatus) => void;

  constructor(container: HTMLElement, onChange: (status: CaptchaStatus) => void) {
    this.container = container;
    this.onChange = onChange;
  }

  private release(): boolean {
    this.generation += 1;
    this.token = "";
    this.validUntil = 0;
    const id = this.widgetId;
    const sdk = this.sdk;
    this.widgetId = null;
    this.sdk = null;
    if (id !== null && sdk !== null) {
      try { sdk.remove(id); } catch { return false; }
    }
    return true;
  }

  private notify(status: CaptchaStatus): void {
    this.status = status;
    this.onChange(status);
  }

  async mount(value: unknown): Promise<void> {
    if (!this.release()) { this.notify("error"); return; }
    const configuration = parseCaptchaConfiguration(value);
    if (configuration === null || !this.container.isConnected) {
      this.notify("error");
      return;
    }
    const generation = this.generation;
    const current = (): boolean => generation === this.generation && this.container.isConnected;
    const fail = (status: "expired" | "error"): void => {
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
        callback: (token: unknown) => {
          if (!current()) return;
          if (typeof token !== "string" || token === "" || token.length > 2048) {
            fail("error");
            return;
          }
          this.token = token;
          this.validUntil = Date.now() + 300_000;
          this.notify("verified");
        },
        "expired-callback": () => fail("expired"),
        "error-callback": () => fail("error"),
        "timeout-callback": () => fail("expired"),
        "unsupported-callback": () => fail("error"),
      });
      if (typeof id !== "string" || id === "") { fail("error"); return; }
      if (!current()) { sdk.remove(id); return; }
      this.widgetId = id;
    } catch {
      fail("error");
    }
  }

  /** Returns a token once. A new mount is required for any subsequent submission. */
  takeToken(): string {
    const token = this.status === "verified" && this.container.isConnected
      && Date.now() < this.validUntil ? this.token : "";
    this.destroy();
    return token;
  }

  destroy(): void {
    this.notify(this.release() ? "destroyed" : "error");
  }
}
