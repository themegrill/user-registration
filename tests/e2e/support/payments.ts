import { test, type Browser, type Page } from "@playwright/test";
import { BASE_URL, uniqueEmail, uniqueUsername, STRONG_PASSWORD } from "./env";
import { createHmac } from "node:crypto";
import { deleteRows, insertRow, postRaw, seedAvailable, selectRows, setHttpStubs, setStripeStubs, type HttpStub } from "./seed";
import { ensureMembershipEnabled, loginToMyAccount } from "./urm";
import { gotoAdminPage, loginAsAdmin, newVisitor, restNonce } from "./wp";

/**
 * Membership state for payment specs: a plan, a member, and the subscription and
 * order rows a gateway would have left behind.
 *
 * The rows are inserted straight into the membership tables, because the only
 * product path that writes them is a real checkout, which needs a gateway. The
 * plan is NOT hand-built: its `ur_membership` meta is a JSON document the
 * product owns, so it is created through the product's own admin endpoint, the
 * same call the "Add Membership" screen makes.
 *
 * Every helper returns a `cleanup` and nothing here relies on deleting the user
 * to remove the rows: the tables declare `ON DELETE CASCADE`, and on Playground's
 * SQLite it does not fire (measured: both rows survived the user's deletion).
 * Children go first, then parents, in the order the foreign keys need.
 */

export const T = {
  subscriptions: "ur_membership_subscriptions",
  orders: "ur_membership_orders",
  ordermeta: "ur_membership_ordermeta",
  events: "ur_membership_subscription_events",
} as const;

const sql = (d: Date) => d.toISOString().slice(0, 19).replace("T", " ");
const daysFromNow = (n: number) => sql(new Date(Date.now() + n * 86_400_000));

/**
 * Create a plan through the product's own AJAX action and return its id.
 *
 * Only `free` is usable on the free plugin: a `subscription` plan is refused
 * without the pro plugin (`validate_membership_data`). Created BEFORE any gateway
 * is configured, since a paid plan on a site with Stripe enabled makes the
 * product call Stripe to sync a price.
 */
export async function createPlan(
  page: Page,
  opts: { name?: string; type?: "free" | "paid" } = {},
): Promise<number> {
  await ensureMembershipEnabled(page);
  await gotoAdminPage(page, "user-registration-membership", "&action=add_new_membership");

  const name = opts.name ?? `QA Plan ${Date.now().toString(36)}`;
  const type = opts.type ?? "free";

  const res = await page.evaluate(
    async ({ name, type }) => {
      const data = (window as unknown as { ur_membership_localized_data: { _nonce: string; ajax_url: string } })
        .ur_membership_localized_data;
      const body = new URLSearchParams({
        action: "user_registration_membership_create_membership",
        security: data._nonce,
        membership_data: JSON.stringify({
          post_data: { name, description: "", status: true },
          post_meta_data: {
            type,
            role: "subscriber",
            amount: type === "free" ? "" : "10",
            payment_gateways: {},
            status: "active",
          },
        }),
      });
      const r = await fetch(data.ajax_url, { method: "POST", body, credentials: "same-origin" });
      return r.json();
    },
    { name, type },
  );

  if (!res?.success) {
    throw new Error(`the product refused to create the plan: ${JSON.stringify(res?.data ?? res)}`);
  }
  return Number(res.data.membership_id);
}

/**
 * Delete a plan through the product's own AJAX action, and fail if it refuses.
 *
 * Not the REST API: the `ur_membership` post type is not REST-exposed, so a
 * `DELETE /wp/v2/ur_membership/<id>` is a 404 that a teardown which does not read
 * the answer reports as success — and the plan then shows up as a radio option on
 * every registration form after it. The product also refuses to delete a plan
 * that still has Active, Pending or Trial members, which is why `cleanup` removes
 * the subscription rows first.
 */
export async function deletePlan(page: Page, planId: number): Promise<void> {
  await gotoAdminPage(page, "user-registration-membership");
  const res = await page.evaluate(async (planId) => {
    const data = (window as unknown as { ur_membership_localized_data?: { _nonce: string; ajax_url: string } })
      .ur_membership_localized_data;
    if (!data) return { success: false, data: { message: "the membership screen did not expose its nonce" } };
    const body = new URLSearchParams({
      action: "user_registration_membership_delete_membership",
      security: data._nonce,
      membership_id: String(planId),
    });
    const r = await fetch(data.ajax_url, { method: "POST", body, credentials: "same-origin" });
    return r.json();
  }, planId);

  if (!res?.success) {
    throw new Error(`the product refused to delete plan ${planId}: ${JSON.stringify(res?.data ?? res)}`);
  }
}

async function createUser(page: Page): Promise<{ id: number; username: string }> {
  const nonce = await restNonce(page);
  const username = uniqueUsername("seed");
  const res = await page.evaluate(
    async ({ nonce, username, email, password }) => {
      const r = await fetch("/wp-json/wp/v2/users", {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-WP-Nonce": nonce },
        credentials: "same-origin",
        body: JSON.stringify({ username, email, password, roles: ["subscriber"] }),
      });
      return { ok: r.ok, status: r.status, json: await r.json() };
    },
    { nonce, username, email: uniqueEmail("seed"), password: STRONG_PASSWORD },
  );
  if (!res.ok) throw new Error(`creating the member failed (HTTP ${res.status}): ${JSON.stringify(res.json)}`);
  return { id: Number(res.json.id), username };
}

async function deleteUser(page: Page, userId: number): Promise<void> {
  const nonce = await restNonce(page);
  await page.evaluate(
    async ({ userId, nonce }) => {
      await fetch(`/wp-json/wp/v2/users/${userId}?force=true&reassign=1`, {
        method: "DELETE",
        headers: { "X-WP-Nonce": nonce },
        credentials: "same-origin",
      });
    },
    { userId, nonce },
  );
}

/**
 * Give a plan the billing terms a subscription plan carries: `{ value: 1, duration: "month" }`.
 *
 * The free plugin refuses to CREATE a subscription-type plan (`validate_membership_data`
 * needs Pro), but handlers that renew one only read these two fields from the plan's
 * stored meta. So the terms are added to the meta the product itself wrote, read back
 * and re-inserted, rather than building the product's JSON document by hand. Needs
 * `postmeta` in `seed_tables`.
 */
export async function setPlanBillingTerms(
  planId: number,
  terms: { value: number; duration: "day" | "week" | "month" | "year" },
): Promise<void> {
  const [row] = await selectRows<{ meta_id: string; meta_value: string }>("postmeta", {
    post_id: planId,
    meta_key: "ur_membership",
  });
  if (!row) throw new Error(`plan ${planId} has no ur_membership meta to extend`);

  const meta = { ...JSON.parse(row.meta_value), subscription: terms };
  await deleteRows("postmeta", { meta_id: row.meta_id });
  await insertRow("postmeta", { post_id: planId, meta_key: "ur_membership", meta_value: JSON.stringify(meta) });
}

export type SeedSubscriptionOptions = {
  /** An existing plan. Without one a plan is created, and removed in cleanup. */
  planId?: number;
  /**
   * The type of the plan created when `planId` is not given. `paid` for anything
   * that records an order amount: a free plan has an empty amount, and the order
   * service fatals on it (`number_format('')`), which no real subscription reaches.
   */
  planType?: "free" | "paid";
  /** Billing terms to write onto the plan this call creates. Ignored when `planId` is given. */
  billingTerms?: { value: number; duration: "day" | "week" | "month" | "year" };
  status?: "active" | "canceled" | "expired" | "trial" | "pending";
  billingCycle?: "day" | "week" | "month" | "year";
  amount?: number;
  /** Days from now. Negative means already due. */
  nextBillingInDays?: number;
  /**
   * Exact stored dates (`YYYY-MM-DD HH:MM:SS`). When `expiryDate` is given the next
   * billing date is set to it too, which is how a renewal handler sees a paid-up cycle.
   */
  startDate?: string;
  expiryDate?: string;
  /** The gateway's own subscription id, which webhooks look the row up by. */
  gatewaySubscriptionId?: string;
  paymentMethod?: string;
  order?: false | { status?: "pending" | "failed" | "completed" | "refunded"; transactionId?: string };
};

export type SeededSubscription = {
  userId: number;
  /** The member's login, so a spec can sign in as them and look at what they see. */
  username: string;
  password: string;
  planId: number;
  subscriptionId: number;
  orderId: number | null;
  /** Remove what this call created, children first. Safe to call twice. */
  cleanup: () => Promise<void>;
};

/**
 * Create a member with a subscription (and, by default, one order) and return
 * the ids plus a cleanup. Call `cleanup` from a fixture teardown, not the test
 * body, so a failing assertion still removes the rows.
 */
export async function seedSubscription(
  page: Page,
  o: SeedSubscriptionOptions = {},
): Promise<SeededSubscription> {
  const ownsPlan = o.planId === undefined;
  const planId = o.planId ?? (await createPlan(page, { type: o.planType }));
  if (ownsPlan && o.billingTerms) await setPlanBillingTerms(planId, o.billingTerms);
  const { id: userId, username } = await createUser(page);

  let subscriptionId = 0;
  let orderId: number | null = null;
  let cleaned = false;

  const cleanup = async () => {
    if (cleaned) return;
    cleaned = true;
    if (subscriptionId) {
      await deleteRows(T.events, { subscription_id: subscriptionId });
      const orders = await selectRows<{ ID: string }>(T.orders, { subscription_id: subscriptionId });
      for (const order of orders) await deleteRows(T.ordermeta, { order_id: order.ID });
      await deleteRows(T.orders, { subscription_id: subscriptionId });
      await deleteRows(T.subscriptions, { ID: subscriptionId });
    }
    await deleteUser(page, userId);
    if (ownsPlan) await deletePlan(page, planId);
  };

  try {
    subscriptionId = await insertRow(T.subscriptions, {
      user_id: userId,
      item_id: planId,
      start_date: o.startDate ?? daysFromNow(-30),
      expiry_date: o.expiryDate ?? daysFromNow(335),
      next_billing_date: o.expiryDate ?? daysFromNow(o.nextBillingInDays ?? 0),
      billing_cycle: o.billingCycle ?? "month",
      billing_amount: o.amount ?? 10,
      status: o.status ?? "active",
      subscription_id: o.gatewaySubscriptionId ?? null,
    });

    // The product shows the Membership tab on My Account to a user it registered
    // as a member; a bare WordPress user would never see the status under test.
    await insertRow("usermeta", { user_id: userId, meta_key: "ur_registration_source", meta_value: "membership" });

    if (o.order !== false) {
      orderId = await insertRow(T.orders, {
        item_id: planId,
        user_id: userId,
        subscription_id: subscriptionId,
        created_by: userId,
        transaction_id: o.order?.transactionId ?? `tgqa_${subscriptionId}`,
        payment_method: o.paymentMethod ?? "stripe",
        total_amount: o.amount ?? 10,
        status: o.order?.status ?? "completed",
        order_type: "subscription",
      });
    }
  } catch (err) {
    await cleanup();
    throw err;
  }

  return { userId, username, password: STRONG_PASSWORD, planId, subscriptionId, orderId, cleanup };
}

/** `Stripe-Signature` for a body: `t=<seconds>,v1=HMAC_SHA256(secret, "<t>.<body>")`. */
export function signStripe(body: string, secret: string, at = Math.floor(Date.now() / 1000)): string {
  const v1 = createHmac("sha256", secret).update(`${at}.${body}`).digest("hex");
  return `t=${at},v1=${v1}`;
}

/**
 * Deliver a Stripe event to the membership webhook the way Stripe does.
 *
 * The signature is real, so the product's own `Webhook::constructEvent` check is
 * exercised rather than bypassed: seed the signing secret first
 * (`user_registration_stripe_webhook_secret_test`) and pass the same one here.
 * The handler then re-fetches the event from Stripe by id, which is why the event
 * must also be registered with `stubEvent`.
 */
export async function postStripeWebhook(
  event: { id: string; type: string; data: { object: Record<string, unknown> } },
  secret: string,
): Promise<{ status: number; text: string }> {
  const body = JSON.stringify(event);
  return postRaw("/wp-json/user-registration/stripe-webhook", body, {
    "Stripe-Signature": signStripe(body, secret),
  });
}

/** Register the `GET /v1/events/<id>` answer the handler's re-fetch will ask for. */
export const stubEvent = (event: { id: string }) =>
  setStripeStubs([{ method: "GET", path: `/v1/events/${event.id}`, body: { object: "event", ...event } }]);

const PAYPAL_SANDBOX = "https://api-m.sandbox.paypal.com";

/** The options a PayPal test-mode account needs before the webhook route will even look at a request. */
export const paypalTestOptions = {
  user_registration_paypal_enabled: "1",
  user_registration_global_paypal_mode: "test",
  user_registration_global_paypal_test_client_id: "tgqa_client",
  user_registration_global_paypal_test_client_secret: "tgqa_secret",
  user_registration_global_paypal_test_webhook_id: "WH-TGQA",
} as const;

/**
 * Stub PayPal's two calls behind webhook verification: the OAuth token, and the
 * verify-webhook-signature call that decides whether a delivery is genuine.
 *
 * `extra` adds more stubs (they match first, so they can narrow by `query`).
 *
 * `SUCCESS` makes every delivery look genuine. That is NOT a test of PayPal's
 * signature scheme: PayPal verifies against a certificate it hosts, so a locally
 * computed signature cannot be made valid. What it does test is everything on
 * the product's side of that call: that a verified event is acted on, and that a
 * refused one is not. Use `FAILURE` for the latter.
 */
export const stubPaypalVerification = (verdict: "SUCCESS" | "FAILURE", extra: HttpStub[] = []) =>
  setHttpStubs([
    ...extra,
    { method: "POST", url: `${PAYPAL_SANDBOX}/v1/oauth2/token`, body: { access_token: "tgqa_token", token_type: "Bearer" } },
    {
      method: "POST",
      url: `${PAYPAL_SANDBOX}/v1/notifications/verify-webhook-signature`,
      body: { verification_status: verdict },
    },
  ]);

/**
 * Deliver a PayPal event to the membership webhook. The transmission headers are
 * placeholders; the verification call that would read them is stubbed.
 */
export const postPaypalWebhook = (event: { id: string; event_type: string; resource: Record<string, unknown> }) =>
  postRaw("/wp-json/user-registration/paypal-webhook", JSON.stringify(event), {
    "PayPal-Transmission-Id": "tgqa-transmission",
    "PayPal-Transmission-Time": new Date().toISOString(),
    "PayPal-Cert-Url": "https://api-m.sandbox.paypal.com/tgqa-cert",
    "PayPal-Auth-Algo": "SHA256withRSA",
    "PayPal-Transmission-Sig": "tgqa-signature",
  });

/** The `custom_id` PayPal echoes back: `<plan>-<member>-<current plan>-<subscription row>`. */
export const paypalCustomId = (s: { planId: number; userId: number; subscriptionId: number }) =>
  `${s.planId}-${s.userId}-${s.planId}-${s.subscriptionId}`;

/**
 * A user-meta value as stored (PHP-serialised when the product saved an array).
 * Needs `usermeta` in `seed_tables`. Null when the key is absent.
 */
export async function userMeta(userId: number, key: string): Promise<string | null> {
  const [row] = await selectRows<{ meta_value: string | null }>("usermeta", { user_id: userId, meta_key: key });
  return row ? (row.meta_value ?? "") : null;
}

/**
 * One string field out of a serialised PHP array, e.g. `status` from
 * `a:2:{s:6:"status";s:4:"open";…}`. Enough to assert a flag the product stores as
 * an array without re-implementing `unserialize`; it reads string values only.
 */
export function serialisedField(serialised: string | null, field: string): string | null {
  if (serialised === null) return null;
  const m = new RegExp(`s:${field.length}:"${field}";s:\\d+:"([^"]*)"`).exec(serialised);
  return m ? m[1] : null;
}

/**
 * One plan for a whole describe block, instead of one per test.
 *
 * Creating a plan goes through the product's admin screen, which is most of the
 * cost of seeding a subscription: about fifteen seconds of a twenty-second test
 * locally, and Playground in CI is several times slower than that. A plan is only
 * the thing a subscription points at, so tests that do not edit it share one.
 * Call it inside the `describe`, and pass `plan.id` as `planId` to
 * `seedSubscription`, which then leaves the plan alone in its own cleanup.
 *
 * The plan is removed after the block. The product refuses to delete a plan that
 * still has members, which is fine: every test's cleanup removes its own rows
 * first, and a plan that cannot be deleted is a leak worth failing on.
 */
export function useSharedPlan(opts: {
  type?: "free" | "paid";
  billingTerms?: SeedSubscriptionOptions["billingTerms"];
} = {}): { id: number } {
  const plan = { id: 0 };

  const adminPage = async (browser: import("@playwright/test").Browser) => {
    const context = await browser.newContext({ baseURL: BASE_URL, ignoreHTTPSErrors: true });
    const page = await context.newPage();
    await loginAsAdmin(page);
    return { context, page };
  };

  test.beforeAll(async ({ browser }) => {
    if (!seedAvailable()) return;
    const { context, page } = await adminPage(browser);
    try {
      plan.id = await createPlan(page, { type: opts.type });
      if (opts.billingTerms) await setPlanBillingTerms(plan.id, opts.billingTerms);
    } finally {
      await context.close();
    }
  });

  test.afterAll(async ({ browser }) => {
    if (!plan.id) return;
    const { context, page } = await adminPage(browser);
    try {
      await deletePlan(page, plan.id);
    } finally {
      await context.close();
      plan.id = 0;
    }
  });

  return plan;
}

/**
 * Sign in as the seeded member in their own browser context and open the
 * Membership tab, the screen a member reads their subscription status on.
 * Returns the page and the status text as printed, e.g. "Active".
 */
export async function memberMembershipStatus(
  browser: Browser,
  member: Pick<SeededSubscription, "username" | "password">,
): Promise<{
  page: Page;
  status: () => Promise<string>;
  /** The "Next Billing Date" cell as the member reads it, e.g. "November 8, 2026" or "N/A". */
  nextBilling: () => Promise<string>;
  close: () => Promise<void>;
}> {
  const context = await newVisitor(browser);
  const page = await context.newPage();
  await loginToMyAccount(page, member.username, member.password);
  await page.goto("/my-account/ur-membership/");
  const label = page.locator("#ur-membership-status");
  return {
    page,
    status: async () => {
      await page.goto("/my-account/ur-membership/");
      return ((await label.first().textContent({ timeout: 15_000 })) ?? "").trim();
    },
    nextBilling: async () => {
      await page.goto("/my-account/ur-membership/");
      return ((await page.locator(".ur-account-table__cell--billing-date").first().textContent({ timeout: 15_000 })) ?? "").trim();
    },
    close: () => context.close(),
  };
}

/** What the member's Membership tab says right now, read in a fresh sign-in. */
export async function memberSees(browser: Browser, member: Pick<SeededSubscription, "username" | "password">): Promise<string> {
  const m = await memberMembershipStatus(browser, member);
  try {
    return await m.status();
  } finally {
    await m.close();
  }
}
