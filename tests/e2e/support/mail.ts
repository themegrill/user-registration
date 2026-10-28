import { test } from "@playwright/test";
import { capturedMail, captureMail, seedAvailable, type CapturedMail } from "./seed";

/**
 * Mail for the specs that assert an email was sent, from one of two places.
 *
 * 1. **The seed endpoint's capture** (`tgqa-seed.php`), whenever the runner staged
 *    it, which is every disposable site it boots. It records what `wp_mail()` is
 *    asked to send and sends nothing, so it works on Playground, where no mail can
 *    leave the machine and no catcher can be reached. This is the one that runs in
 *    CI.
 * 2. **Mailpit**, on a developer machine that runs one (Local by Flywheel runs one
 *    per site), as before.
 *
 * With neither, a spec SKIPS: a red test for missing infrastructure trains people
 * to ignore red tests, and a silent pass would be worse still. A spec calls
 * `startMailCapture()` before the action that sends mail and `stopMailCapture()` in
 * teardown; both are no-ops against Mailpit.
 */
const BASE = process.env.TGQA_MAILPIT_URL ?? "http://127.0.0.1:10000";

export type Message = {
  ID: string;
  Subject: string;
  To: { Address: string }[];
  From: { Address: string } | null;
  Created: string;
};

type Backend = "capture" | "mailpit";

/** Which mail source this run has, or null when it has none. */
export async function mailBackend(): Promise<Backend | null> {
  // The seed endpoint first: it is the only source that exists on a Playground
  // site, and on a disposable site it is always the right one.
  if (seedAvailable()) return "capture";

  // Without it, Playground has no outbound mail at all, so nothing the site sends
  // can arrive whatever is listening. Reachability alone is the wrong question
  // there, and answering "yes" is actively misleading on a developer machine:
  // Local runs a Mailpit per site, so a suite pointed at a Playground site while
  // Local is up finds a healthy catcher on :10000, waits the full 30s for mail that
  // was never going to leave, and reports a red email test for a site that cannot
  // send email.
  if ((process.env.TGQA_ENV ?? "") === "playground") return null;

  try {
    const r = await fetch(`${BASE}/api/v1/messages?limit=1`, { signal: AbortSignal.timeout(3000) });
    return r.ok ? "mailpit" : null;
  } catch {
    return null;
  }
}

/** Is any mail source available? Used to skip, not to fail. */
export const mailAvailable = async (): Promise<boolean> => (await mailBackend()) !== null;

/**
 * Skip when there is no mail source, but FAIL on CI, where the seed endpoint is
 * always staged and its absence is a workflow fault. See `requireSeed`.
 */
export async function requireMail(): Promise<void> {
  if (await mailAvailable()) return;
  const reason = "no mail source: no seed endpoint and no Mailpit — set TGQA_MAILPIT_URL or run on a site the runner booted";
  if (process.env.CI) throw new Error(`${reason} — on CI this is a workflow fault, not a skip`);
  test.skip(true, reason);
}

/** Begin recording mail. Clears what an earlier spec left. A no-op against Mailpit. */
export async function startMailCapture(): Promise<void> {
  if (seedAvailable()) await captureMail(true);
}

/** Stop recording, so the site sends mail normally again. Call from teardown. */
export async function stopMailCapture(): Promise<void> {
  if (seedAvailable()) await captureMail(false);
}

/** `Name <a@b>` or `a@b` to `a@b`. */
const addressOf = (to: string) => /<([^>]+)>/.exec(to)?.[1] ?? to.trim();

/** A captured mail in Mailpit's shape, so the specs read one way. The id is its position in the log. */
const fromCapture = (m: CapturedMail, i: number): Message => ({
  ID: String(i),
  Subject: m.subject,
  To: m.to.map((t) => ({ Address: addressOf(t) })),
  From: null,
  // Capture is cleared when it starts, so everything in it is from this spec; a
  // cutoff comparison against "now" is therefore always satisfied.
  Created: new Date().toISOString(),
});

/** Every message currently held, newest first. */
async function recent(limit = 100): Promise<Message[]> {
  if (seedAvailable()) return (await capturedMail()).map(fromCapture).reverse().slice(0, limit);

  const r = await fetch(`${BASE}/api/v1/messages?limit=${limit}`, {
    signal: AbortSignal.timeout(5000),
  });
  if (!r.ok) return [];
  const body = (await r.json()) as { messages?: Message[] };
  return body.messages ?? [];
}

/**
 * Poll for a message matching `match`, up to `timeoutMs`.
 *
 * Polling rather than a single read because mail delivery is asynchronous to
 * the HTTP response that triggered it — asserting immediately after a form
 * submit is the classic way to write a flaky email test.
 */
export async function waitForMessage(
  match: (m: Message) => boolean,
  timeoutMs = 30_000,
): Promise<Message | null> {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const found = (await recent()).find(match);
    if (found) return found;
    await new Promise((r) => setTimeout(r, 1000));
  }
  return null;
}

/** The rendered HTML body of one message, or null when it cannot be read. */
export async function messageHtml(id: string): Promise<string | null> {
  if (seedAvailable()) return (await capturedMail())[Number(id)]?.message ?? null;

  const r = await fetch(`${BASE}/api/v1/message/${encodeURIComponent(id)}`, {
    signal: AbortSignal.timeout(5000),
  });
  if (!r.ok) return null;
  const body = (await r.json()) as { HTML?: string };
  return body.HTML ?? null;
}

/** True when the message is addressed to this exact address. */
export const addressedTo = (email: string) => (m: Message) =>
  (m.To ?? []).some((t) => t.Address?.toLowerCase() === email.toLowerCase());

/**
 * Deliberately no purge/delete helper.
 *
 * This mailbox is a real developer inbox holding hundreds of unrelated
 * messages. Every assertion here matches on a unique per-run address, so
 * isolation needs no destruction — and a teardown that empties someone's inbox
 * to make a test tidy is not a trade worth making.
 */
