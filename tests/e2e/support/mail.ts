/**
 * Minimal Mailpit client, for the specs that assert an email was actually sent.
 *
 * Local by Flywheel runs one Mailpit per site; this one answers on
 * http://127.0.0.1:10000 for test-urm. That is a developer-machine detail, so
 * it is overridable and its absence is a SKIP, never a failure: a CI runner
 * with no mail catcher must not turn these red, and a spec that silently
 * passes without a catcher would be worse still.
 */
const BASE = process.env.TGQA_MAILPIT_URL ?? "http://127.0.0.1:10000";

export type Message = {
  ID: string;
  Subject: string;
  To: { Address: string }[];
  From: { Address: string } | null;
  Created: string;
};

/** Is a mail catcher reachable? Used to skip, not to fail. */
export async function mailAvailable(): Promise<boolean> {
  // Playground has no outbound mail at all — `boot-wp.mjs` reports it as a
  // caveat of the engine — so nothing the site sends can ever arrive, whatever
  // is listening. Reachability alone is the wrong question there, and answering
  // it "yes" is actively misleading on a developer machine: Local runs a Mailpit
  // per site, so a suite pointed at a Playground site while Local is up finds a
  // healthy catcher on :10000, waits the full 30s for mail that was never going
  // to leave, and reports a red email test for a site that cannot send email.
  if ((process.env.TGQA_ENV ?? "") === "playground") return false;

  try {
    const r = await fetch(`${BASE}/api/v1/messages?limit=1`, {
      signal: AbortSignal.timeout(3000),
    });
    return r.ok;
  } catch {
    return false;
  }
}

/** Every message currently held, newest first. */
async function recent(limit = 100): Promise<Message[]> {
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
