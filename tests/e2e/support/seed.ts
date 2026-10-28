import { request, type APIRequestContext } from "@playwright/test";

/**
 * Put the site into a state a click cannot reach, over HTTP.
 *
 * Gateway keys, a renewal that is due today, a subscription with no order: these
 * are the states the payment code paths run in, and none of them is reachable
 * from the UI of a fresh site. Playground has no shell and no real cron, so
 * `wp option update` and `wp cron event run` are not available there either.
 * claudegrill's `tgqa-seed.php` mu-plugin is the one channel every engine has;
 * this file is the typed client for it.
 *
 * The endpoint exists only on a disposable site the runner booted (or a local
 * one it installed the probe into), and answers only to the run's token. When it
 * is not there, `seedAvailable()` is false and a spec should skip with that
 * reason — never pass without having seeded what it claims to test.
 */

const ABSENT = "__tgqa_absent__";

/** The probe URL the runner exported, rewritten to address the seed endpoint. */
function seedBase(): { origin: string; token: string } | null {
  const probe = process.env.TGQA_PROBE_URL;
  if (!probe) return null;
  try {
    const u = new URL(probe);
    const token = u.searchParams.get("tgqa_probe");
    return token ? { origin: u.origin, token } : null;
  } catch {
    return null;
  }
}

/** True when the runner staged the seed endpoint for this run. */
export const seedAvailable = (): boolean => seedBase() !== null;

let ctx: APIRequestContext | null = null;

/**
 * One request context for the run, warmed with a GET.
 *
 * Playground's autologin answers the first request with a redirect that sets
 * cookies; a POST sent into it loses its body on the way through. Sending a GET
 * first leaves the context holding the cookie, so every POST after it lands
 * directly.
 */
async function client(): Promise<APIRequestContext> {
  if (ctx) return ctx;
  const base = seedBase();
  if (!base) throw new Error("no TGQA_PROBE_URL: the seed endpoint was not staged for this run");
  ctx = await request.newContext({ baseURL: base.origin });
  await ctx.get("/");
  return ctx;
}

async function call<T>(op: string, body?: unknown, query = ""): Promise<T> {
  const base = seedBase();
  if (!base) throw new Error("no TGQA_PROBE_URL: the seed endpoint was not staged for this run");
  const c = await client();
  const url = `/?tgqa_seed=${base.token}&op=${op}${query}`;
  const res = body === undefined ? await c.get(url) : await c.post(url, { data: body });
  const json = await res.json().catch(() => ({}));
  if (!res.ok()) {
    throw new Error(`seed ${op} refused (HTTP ${res.status()}): ${json.error ?? "no reason given"}`);
  }
  return json as T;
}

/**
 * Set options and return what was there before, to hand back to `restoreOptions`.
 * An option that did not exist is reported with the absent sentinel, so a restore
 * deletes it rather than leaving a row behind.
 */
export async function setOptions(values: Record<string, unknown>): Promise<Record<string, unknown>> {
  const res = await call<{ previous: Record<string, unknown> }>("options", values);
  return res.previous;
}

export const restoreOptions = async (previous: Record<string, unknown>): Promise<void> => {
  if (Object.keys(previous).length) await call("options", previous);
};

export async function getOptions(...keys: string[]): Promise<Record<string, unknown>> {
  const res = await call<{ options: Record<string, unknown> }>("options", undefined, `&keys=${keys.join(",")}`);
  return res.options;
}

export const isAbsent = (value: unknown): boolean => value === ABSENT;

export type CronResult = { hook: string; callbacks: number; ran: boolean; errors: string[]; output: string };

/**
 * Run a scheduled cron hook now. Refused by the endpoint when the hook is not
 * scheduled or has nothing attached, so a result here means a handler ran. A
 * handler that threw comes back with `ran: false`; callers assert on it.
 */
export const runCron = (hook: string, args: unknown[] = []): Promise<CronResult> =>
  call<CronResult>("cron", { hook, args });

/** Insert a row in a table declared in `seed_tables`; returns its auto id. */
export async function insertRow(table: string, row: Record<string, unknown>): Promise<number> {
  const res = await call<{ id: number }>("rows", { action: "insert", table, row });
  return res.id;
}

export async function selectRows<T = Record<string, string | null>>(
  table: string,
  where: Record<string, unknown>,
): Promise<T[]> {
  const res = await call<{ rows: T[] }>("rows", { action: "select", table, where });
  return res.rows;
}

export async function deleteRows(table: string, where: Record<string, unknown>): Promise<number> {
  const res = await call<{ deleted: number }>("rows", { action: "delete", table, where });
  return res.deleted;
}

export type StripeStub = {
  method: string;
  /** The API path only, e.g. `/v1/events/evt_1`. */
  path: string;
  status?: number;
  body?: unknown;
};

export type StubCall = {
  via: string;
  method: string;
  path: string;
  params: unknown;
  matched: boolean;
};

/**
 * Answer the Stripe SDK's outbound calls from this list instead of the network.
 *
 * While any stub is registered the SDK never reaches api.stripe.com: a call with
 * no matching stub gets a 500 and shows up in `stubCalls()` as `matched: false`.
 * Registering replaces the previous list and clears the call log.
 */
export const setStripeStubs = async (stubs: StripeStub[]): Promise<void> => {
  await setStubs(stubs.map((s) => ({ via: "stripe" as const, ...s })));
};

export type HttpStub = {
  method: string;
  /** The full URL without a query string, e.g. `https://api-m.sandbox.paypal.com/v1/oauth2/token`. */
  url: string;
  /** Only match requests whose query string contains this. The first matching stub wins, so put a narrow one before a catch-all. */
  query?: string;
  status?: number;
  body?: unknown;
};

/**
 * Answer outbound `wp_remote_*` requests (PayPal, and anything else that goes
 * through WordPress's HTTP API) from this list. A request to a host that has a
 * stub but matches none is refused with a 500 and logged as unmatched; a request
 * to any other host is untouched. Replaces the previous list.
 */
export const setHttpStubs = async (stubs: HttpStub[]): Promise<void> => {
  await setStubs(stubs.map((s) => ({ via: "http" as const, ...s })));
};

/** Register a mixed list. Prefer the two typed wrappers above. */
export async function setStubs(
  stubs: Array<({ via: "stripe" } & StripeStub) | ({ via: "http" } & HttpStub)>,
): Promise<void> {
  await call("stubs", { set: stubs });
}

export const clearStubs = async (): Promise<void> => {
  await call("stubs", { clear: true });
};

export async function stubCalls(): Promise<StubCall[]> {
  const res = await call<{ calls: StubCall[] }>("stubs");
  return res.calls;
}

/**
 * POST an exact byte string to the site, and report status and body.
 *
 * A webhook signature covers the raw body, so this sends the string as given
 * rather than letting a JSON helper re-serialise it.
 */
export async function postRaw(
  path: string,
  body: string,
  headers: Record<string, string>,
): Promise<{ status: number; text: string }> {
  const c = await client();
  const res = await c.post(path, { data: body, headers: { "Content-Type": "application/json", ...headers } });
  return { status: res.status(), text: await res.text() };
}

export type CapturedMail = { to: string[]; subject: string; message: string; headers: unknown };

/**
 * Record what `wp_mail()` is asked to send instead of sending it. Turning capture
 * on clears the log; turning it off leaves the log readable. Always turn it off in
 * teardown: a capturing site sends no mail at all.
 */
export const captureMail = async (on: boolean): Promise<void> => {
  await call("mail", { capture: on });
};

export async function capturedMail(): Promise<CapturedMail[]> {
  const res = await call<{ mail: CapturedMail[] }>("mail");
  return res.mail;
}
