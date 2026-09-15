import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { stripTypeScriptTypes } from "node:module";
import test from "node:test";

const source = await readFile(new URL("../src/captchaWidget.ts", import.meta.url), "utf8");
const javascript = stripTypeScriptTypes(source);
let moduleSequence = 0;
const config = { kind: "turnstile", site_key: "public-key", action: "login",
  application_binding: "application_a" };

async function harness(t, { installed = true } = {}) {
  const previous = Object.getOwnPropertyDescriptors(globalThis);
  t.after(() => {
    for (const key of ["turnstile", "document"]) {
      if (previous[key]) Object.defineProperty(globalThis, key, previous[key]);
      else delete globalThis[key];
    }
  });
  const options = [];
  const removed = [];
  const scripts = [];
  const sdk = {
    ready(callback) { callback(); },
    render(_container, value) { options.push(value); return `widget-${options.length}`; },
    remove(id) { removed.push(id); },
  };
  globalThis.turnstile = installed ? sdk : undefined;
  globalThis.document = {
    createElement(tag) {
      assert.equal(tag, "script");
      return { remove() { this.removed = true; } };
    },
    head: { append(script) { scripts.push(script); } },
  };
  const module = await import(`data:text/javascript;base64,${Buffer.from(
    `${javascript}\n// isolated test ${++moduleSequence}`,
  ).toString("base64")}`);
  const container = { isConnected: true };
  const states = [];
  const widget = new module.CaptchaWidget(container, (state) => states.push(state));
  t.after(() => widget.destroy());
  return { module, container, widget, states, options, removed, scripts, sdk };
}

test("valid challenge produces exactly one token and binds action and application", async (t) => {
  const h = await harness(t);
  await h.widget.mount(config);
  assert.deepEqual(h.states, ["loading", "waiting"]);
  assert.equal(h.options[0].sitekey, config.site_key);
  assert.equal(h.options[0].action, "login");
  assert.equal(h.options[0].cData, "application_a");
  assert.equal(h.options[0]["response-field"], false);
  h.options[0].callback("signed-token");
  assert.equal(h.states.at(-1), "verified");
  assert.equal(h.widget.takeToken(), "signed-token");
  h.options[0].callback("late-token");
  assert.equal(h.widget.takeToken(), "");
  assert.deepEqual(h.removed, ["widget-1"]);
});

for (const [callback, status] of [
  ["expired-callback", "expired"], ["timeout-callback", "expired"],
  ["error-callback", "error"], ["unsupported-callback", "error"],
]) {
  test(`${callback} clears a solved token and rejects later callbacks`, async (t) => {
    const h = await harness(t);
    await h.widget.mount(config);
    h.options[0].callback("old-token");
    h.options[0][callback]();
    assert.equal(h.states.at(-1), status);
    h.options[0].callback("late-token");
    assert.equal(h.widget.takeToken(), "");
  });
}

test("application/action switch destroys the old challenge and ignores its callbacks", async (t) => {
  const h = await harness(t);
  await h.widget.mount(config);
  h.options[0].callback("old-token");
  await h.widget.mount({ ...config, action: "register", application_binding: "application_b" });
  h.options[0].callback("late-token");
  h.options[0]["error-callback"]();
  assert.equal(h.states.at(-1), "waiting");
  assert.equal(h.options[1].action, "register");
  assert.equal(h.options[1].cData, "application_b");
  h.options[1].callback("new-token");
  assert.equal(h.widget.takeToken(), "new-token");
});

test("detached container cannot submit a previously solved token", async (t) => {
  const h = await harness(t);
  await h.widget.mount(config);
  h.options[0].callback("old-token");
  h.container.isConnected = false;
  assert.equal(h.widget.takeToken(), "");
});

test("script errors can retry using only the fixed SDK URL", async (t) => {
  const h = await harness(t, { installed: false });
  const first = h.widget.mount(config);
  assert.equal(h.scripts[0].src,
    "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit");
  h.scripts[0].onerror();
  await first;
  assert.equal(h.states.at(-1), "error");
  assert.equal(h.scripts[0].removed, true);
  const second = h.widget.mount(config);
  assert.equal(h.scripts.length, 2);
  globalThis.turnstile = h.sdk;
  h.scripts[1].onload();
  await second;
  h.options[0].callback("retry-token");
  assert.equal(h.widget.takeToken(), "retry-token");
});

test("destroy during loading prevents late readiness from rendering", async (t) => {
  const h = await harness(t, { installed: false });
  const mounting = h.widget.mount(config);
  h.widget.destroy();
  globalThis.turnstile = h.sdk;
  h.scripts[0].onload();
  await mounting;
  assert.equal(h.options.length, 0);
  assert.equal(h.states.at(-1), "destroyed");
});

test("switch during loading shares one script but renders only the current application", async (t) => {
  const h = await harness(t, { installed: false });
  const first = h.widget.mount(config);
  const second = h.widget.mount({ ...config, application_binding: "application_b" });
  assert.equal(h.scripts.length, 1);
  globalThis.turnstile = h.sdk;
  h.scripts[0].onload();
  await Promise.all([first, second]);
  assert.equal(h.options.length, 1);
  assert.equal(h.options[0].cData, "application_b");
});

test("SDK readiness timeout fails closed and allows a subsequent retry", async (t) => {
  const h = await harness(t);
  const timers = [];
  const oldSetTimeout = globalThis.setTimeout;
  const oldClearTimeout = globalThis.clearTimeout;
  globalThis.setTimeout = (callback) => { timers.push(callback); return timers.length; };
  globalThis.clearTimeout = () => {};
  t.after(() => { globalThis.setTimeout = oldSetTimeout; globalThis.clearTimeout = oldClearTimeout; });
  let lateReady;
  h.sdk.ready = (callback) => { lateReady = callback; };
  const first = h.widget.mount(config);
  timers[0]();
  await first;
  lateReady();
  assert.equal(h.states.at(-1), "error");
  assert.equal(h.options.length, 0);
  h.sdk.ready = (callback) => callback();
  await h.widget.mount(config);
  assert.equal(h.states.at(-1), "waiting");
});

test("unknown or malformed configuration is rejected before any SDK script loads", async (t) => {
  const h = await harness(t, { installed: false });
  for (const value of [null, {}, { ...config, kind: "external" },
    { ...config, action: "reset" }, { ...config, application_binding: "has spaces" },
    { ...config, site_key: "https://untrusted.example" }]) {
    await h.widget.mount(value);
    assert.equal(h.states.at(-1), "error");
  }
  assert.equal(h.scripts.length, 0);
  assert.deepEqual(h.module.parseCaptchaConfiguration({ ...config, secret_key: "secret" }), config);
});
