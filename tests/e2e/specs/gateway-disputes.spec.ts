import { expect, test } from "@playwright/test";
import {
  paypalTestOptions,
  postPaypalWebhook,
  postStripeWebhook,
  seedSubscription,
  serialisedField,
  stubPaypalVerification,
  T,
  useSharedPlan,
  userMeta,
  type SeededSubscription,
} from "../support/payments";
import {
  captureMail,
  capturedMail,
  clearStubs,
  restoreOptions,
  runCron,
  seedAvailable,
  selectRows,
  setOptions,
  setStripeStubs,
  stubCalls,
} from "../support/seed";
import { loginAsAdmin } from "../support/wp";

/**
 * Chargebacks: a member who disputes a payment with their bank must lose access,
 * and the admin must be able to see why.
 *
 * Before the fix neither gateway's webhook subscribed to or processed dispute
 * events, so a chargeback left membership access intact with no sign of it on the
 * site. A real dispute now refunds the order, cancels the membership and the
 * gateway subscription, and flags the member for review; an early-warning inquiry
 * only flags. A closed dispute keeps access revoked even when the merchant won, so
 * an admin restores it deliberately. Replays and out-of-order deliveries must not
 * undo a later outcome.
 *
 * The admin email these events send is not asserted: nothing on Playground observes
 * mail. Everything else is read back from the rows and the member's stored flag.
 */
const STRIPE_SECRET = "whsec_tgqa_dispute";
const STRIPE_SUB = "sub_tgqa_dispute";
const STRIPE_PI = "pi_tgqa_disputed";
const PAYPAL_SUB = "I-TGQADISPUTE1";
const PAYPAL_TXN = "PAYPAL-TXN-TGQA-1";
const PAYPAL_API = "https://api-m.sandbox.paypal.com";
const ADMIN_MAIL = "payments-admin@example.test";

test.describe("stripe disputes @fresh", () => {
  const plan = useSharedPlan({ type: "paid" });
  let before: Record<string, unknown> | undefined;
  let sub: SeededSubscription | undefined;

  test.beforeEach(async ({ page }) => {
    before = undefined;
    sub = undefined;
    test.skip(!seedAvailable(), "the runner did not stage the seed endpoint, so dispute state cannot be seeded");
    await loginAsAdmin(page);
    sub = await seedSubscription(page, {
      gatewaySubscriptionId: STRIPE_SUB,
      paymentMethod: "stripe",
      planId: plan.id,
      status: "active",
      order: { status: "completed", transactionId: STRIPE_PI },
    });
    before = await setOptions({
      user_registration_stripe_enabled: "1",
      user_registration_stripe_test_mode: "1",
      user_registration_stripe_test_secret_key: "sk_test_tgqa",
      user_registration_stripe_test_publishable_key: "pk_test_tgqa",
      user_registration_stripe_webhook_secret_test: STRIPE_SECRET,
      user_registration_payments_admin_email_receipents: ADMIN_MAIL,
    });
    await captureMail(true);
  });

  test.afterEach(async () => {
    if (!seedAvailable()) return;
    await captureMail(false);
    await clearStubs();
    if (before) await restoreOptions(before);
    if (sub) await sub.cleanup();
  });

  const dispute = (id: string, status: string) => ({
    id,
    object: "dispute",
    status,
    reason: "fraudulent",
    amount: 1000,
    currency: "usd",
    payment_intent: STRIPE_PI,
  });
  const event = (id: string, type: string, status: string) => ({
    id: `evt_${id}`,
    type,
    data: { object: dispute("dp_tgqa_1", status) },
  });

  /** Deliver a dispute event the way Stripe does: stubbed re-fetch, real signature. */
  const deliver = async (e: ReturnType<typeof event>) => {
    // One list: the event re-fetch, and what the product asks Stripe when it cancels the
    // subscription at the gateway. Registering replaces the previous list and its call log.
    await setStripeStubs([
      { method: "GET", path: `/v1/events/${e.id}`, body: { object: "event", ...e } },
      { method: "GET", path: `/v1/subscriptions/${STRIPE_SUB}`, body: { id: STRIPE_SUB, object: "subscription", status: "active" } },
      { method: "DELETE", path: `/v1/subscriptions/${STRIPE_SUB}`, body: { id: STRIPE_SUB, object: "subscription", status: "canceled" } },
    ]);
    const res = await postStripeWebhook(e, STRIPE_SECRET);
    expect(res.status, res.text).toBe(200);
  };

  const order = async () => (await selectRows(T.orders, { ID: sub!.orderId! }))[0];
  const subscription = async () => (await selectRows(T.subscriptions, { ID: sub!.subscriptionId }))[0];
  const flag = async (field: string) => serialisedField(await userMeta(sub!.userId, "urm_stripe_dispute"), field);

  /**
   * @area    membership
   * @tier    fresh
   * @guards  themegrill/user-registration-pro#1717
   * @source  write-spec 2026-10-28
   * @why     A chargeback took the money back while the member kept their access
   *          and nothing on the site said so. A real dispute must refund the order,
   *          cancel the membership, stop the Stripe subscription, and flag the
   *          member for the admin.
   */
  test("a real dispute revokes access, refunds the order and flags the member @fresh @membership", async () => {
    await deliver(event("opened", "charge.dispute.created", "needs_response"));

    expect((await order()).status).toBe("refunded");
    expect((await subscription()).status).toBe("canceled");
    expect(await flag("dispute_id")).toBe("dp_tgqa_1");
    expect(await flag("status")).toBe("open");
    const calls = (await stubCalls()).map((c) => `${c.method} ${c.path}`);
    expect(calls, "the Stripe subscription must be cancelled at the gateway too").toContain(`DELETE /v1/subscriptions/${STRIPE_SUB}`);
    expect((await stubCalls()).filter((c) => !c.matched), "every Stripe call must have been stubbed").toEqual([]);
  });

  /**
   * @area    membership
   * @tier    fresh
   * @guards  themegrill/user-registration-pro#1717
   * @source  write-spec 2026-10-28
   * @why     An inquiry is an early warning: no money has moved. Cutting a paying
   *          member off on a warning would be its own harm, so an inquiry is only
   *          flagged for the admin and access is left alone.
   */
  test("an inquiry is flagged but leaves the order and membership alone @fresh @membership", async () => {
    await deliver(event("inquiry", "charge.dispute.created", "warning_needs_response"));

    expect(await flag("status")).toBe("warning_needs_response");
    expect((await order()).status).toBe("completed");
    expect((await subscription()).status).toBe("active");
    expect((await stubCalls()).map((c) => c.method), "no gateway cancel for an inquiry").not.toContain("DELETE");
  });

  /**
   * @area    membership
   * @tier    fresh
   * @guards  themegrill/user-registration-pro#1717
   * @source  write-spec 2026-10-28
   * @why     Winning a dispute does not hand access back by itself: the product
   *          keeps the membership cancelled so an admin restores it on purpose. And
   *          Stripe can redeliver the opening event after the closing one, which
   *          must not reopen a closed dispute.
   */
  test("a won dispute stays cancelled and a replayed opening event cannot reopen it @fresh @membership", async () => {
    await deliver(event("opened", "charge.dispute.created", "needs_response"));
    await deliver(event("closed", "charge.dispute.closed", "won"));

    expect(await flag("status")).toBe("won");
    expect((await subscription()).status, "a won dispute still leaves access revoked").toBe("canceled");

    await deliver(event("opened", "charge.dispute.created", "needs_response")); // late redelivery

    expect(await flag("status"), "the closed outcome must survive a replayed created event").toBe("won");
    expect((await subscription()).status).toBe("canceled");
  });

  /**
   * @area    membership
   * @tier    fresh
   * @guards  themegrill/user-registration-pro#1717
   * @source  write-spec 2026-10-28
   * @why     The admin only learns of a chargeback from the email, and Stripe
   *          redelivers. The payment admins must be told once when a dispute opens,
   *          not again on a redelivery, and again when it closes (the closing
   *          mail is what tells them to restore access after a win).
   */
  test("the payment admins are emailed once per stage, not per delivery @fresh @membership", async () => {
    const opened = event("opened", "charge.dispute.created", "needs_response");

    await deliver(opened);
    await deliver(opened); // redelivery
    let mail = await capturedMail();
    expect(mail, "one email for the opening, however often it is delivered").toHaveLength(1);
    expect(mail[0].to).toContain(ADMIN_MAIL);
    expect(mail[0].subject).toContain("Stripe dispute opened");
    expect(mail[0].message).toContain("dp_tgqa_1");

    await deliver(event("closed", "charge.dispute.closed", "won"));
    mail = await capturedMail();
    expect(mail, "the close is a second email").toHaveLength(2);
    expect(mail[1].subject).toContain("Stripe dispute closed");
  });

  /**
   * @area    membership
   * @tier    fresh
   * @guards  themegrill/user-registration-pro#1717
   * @source  write-spec 2026-10-28
   * @why     An inquiry still has to reach the admin, with wording that says access
   *          was not changed, or they would assume the member is already cut off.
   */
  test("an inquiry emails the admins that access was not changed @fresh @membership", async () => {
    await deliver(event("inquiry", "charge.dispute.created", "warning_needs_response"));

    const mail = await capturedMail();
    expect(mail).toHaveLength(1);
    expect(mail[0].subject).toContain("Stripe inquiry opened");
    expect(mail[0].message).toContain("membership access has not been changed");
  });
});

test.describe("paypal disputes @fresh", () => {
  const plan = useSharedPlan({ type: "paid" });
  let before: Record<string, unknown> | undefined;
  let sub: SeededSubscription | undefined;

  test.beforeEach(async ({ page }) => {
    before = undefined;
    sub = undefined;
    test.skip(!seedAvailable(), "the runner did not stage the seed endpoint, so dispute state cannot be seeded");
    await loginAsAdmin(page);
    sub = await seedSubscription(page, {
      gatewaySubscriptionId: PAYPAL_SUB,
      paymentMethod: "paypal",
      planId: plan.id,
      status: "active",
      order: { status: "completed", transactionId: PAYPAL_TXN },
    });
    before = await setOptions({ ...paypalTestOptions, user_registration_payments_admin_email_receipents: ADMIN_MAIL });
    // Verification, plus the call the product makes to stop the subscription at PayPal.
    await stubPaypalVerification("SUCCESS", [
      { method: "POST", url: `${PAYPAL_API}/v1/billing/subscriptions/${PAYPAL_SUB}/cancel`, status: 204 },
    ]);
    await captureMail(true);
  });

  test.afterEach(async () => {
    if (!seedAvailable()) return;
    await captureMail(false);
    await clearStubs();
    if (before) await restoreOptions(before);
    if (sub) await sub.cleanup();
  });

  const dispute = (stage: string, status: string, outcome?: string) => ({
    id: `WH-EVT-${stage}-${status}`,
    event_type: "CUSTOMER.DISPUTE.CREATED",
    resource: {
      dispute_id: "PP-D-TGQA-1",
      status,
      dispute_life_cycle_stage: stage,
      reason: "MERCHANDISE_OR_SERVICE_NOT_RECEIVED",
      dispute_amount: { value: "10.00", currency_code: "USD" },
      disputed_transactions: [{ seller_transaction_id: PAYPAL_TXN }],
      ...(outcome ? { dispute_outcome: { outcome_code: outcome } } : {}),
    },
  });
  const deliver = async (e: ReturnType<typeof dispute>, type: string) => {
    const res = await postPaypalWebhook({ ...e, event_type: type });
    expect(res.status, res.text).toBe(200);
  };

  const order = async () => (await selectRows(T.orders, { ID: sub!.orderId! }))[0];
  const subscription = async () => (await selectRows(T.subscriptions, { ID: sub!.subscriptionId }))[0];
  const flag = async (field: string) => serialisedField(await userMeta(sub!.userId, "urm_paypal_dispute"), field);

  /**
   * @area    membership
   * @tier    fresh
   * @guards  themegrill/user-registration-pro#1717
   * @source  write-spec 2026-10-28
   * @why     The same chargeback gap existed on PayPal: a buyer's dispute left the
   *          membership active. A real dispute (a chargeback stage) must refund the
   *          order, cancel the membership and flag the member.
   */
  test("a chargeback dispute revokes access, refunds the order and flags the member @fresh @membership", async () => {
    await deliver(dispute("CHARGEBACK", "OPEN"), "CUSTOMER.DISPUTE.CREATED");

    expect((await order()).status).toBe("refunded");
    expect((await subscription()).status).toBe("canceled");
    expect(await flag("dispute_id")).toBe("PP-D-TGQA-1");
    expect(await flag("status")).toBe("open");
  });

  /**
   * @area    membership
   * @tier    fresh
   * @guards  themegrill/user-registration-pro#1717
   * @source  write-spec 2026-10-28
   * @why     A PayPal inquiry (no chargeback yet) must not cut a member off; it is
   *          flagged only.
   */
  test("an inquiry-stage dispute is flagged but leaves the order and membership alone @fresh @membership", async () => {
    await deliver(dispute("INQUIRY", "OPEN"), "CUSTOMER.DISPUTE.CREATED");

    expect(await flag("dispute_id")).toBe("PP-D-TGQA-1");
    expect((await order()).status).toBe("completed");
    expect((await subscription()).status).toBe("active");
  });

  /**
   * @area    membership
   * @tier    fresh
   * @guards  themegrill/user-registration-pro#1717
   * @source  write-spec 2026-10-28
   * @why     PayPal sends UPDATED events freely and does not guarantee order. Once a
   *          dispute is resolved, a late UPDATED must not downgrade its status, and
   *          a seller win still leaves access revoked for an admin to restore.
   */
  test("a resolved dispute keeps its outcome when a late update arrives @fresh @membership", async () => {
    await deliver(dispute("CHARGEBACK", "OPEN"), "CUSTOMER.DISPUTE.CREATED");
    await deliver(dispute("CHARGEBACK", "RESOLVED", "RESOLVED_SELLER_FAVOUR"), "CUSTOMER.DISPUTE.RESOLVED");

    expect(await flag("status")).toBe("won");
    expect((await subscription()).status).toBe("canceled");

    await deliver(dispute("CHARGEBACK", "UNDER_REVIEW"), "CUSTOMER.DISPUTE.UPDATED"); // late

    expect(await flag("status"), "a late update must not reopen a resolved dispute").toBe("won");
    expect((await subscription()).status).toBe("canceled");
  });

  /**
   * @area    membership
   * @tier    fresh
   * @guards  themegrill/user-registration-pro#1717
   * @source  write-spec 2026-10-28
   * @why     Revoking access on the site is not enough: a PayPal subscription left
   *          running keeps billing a member who has charged the payment back. The
   *          product must also ask PayPal to cancel it.
   */
  test("a chargeback also cancels the subscription at PayPal @fresh @membership", async () => {
    await deliver(dispute("CHARGEBACK", "OPEN"), "CUSTOMER.DISPUTE.CREATED");

    const calls = await stubCalls();
    expect(calls.map((c) => `${c.method} ${c.path.replace(PAYPAL_API, "")}`)).toContain(
      `POST /v1/billing/subscriptions/${PAYPAL_SUB}/cancel`,
    );
    expect(calls.filter((c) => !c.matched), "every PayPal call must have been stubbed").toEqual([]);
  });

  /**
   * @area    membership
   * @tier    fresh
   * @guards  themegrill/user-registration-pro#1717
   * @source  write-spec 2026-10-28
   * @why     PayPal re-sends UPDATED events freely. The payment admins must be told
   *          once when a dispute opens and once when it resolves, never once per
   *          delivery.
   */
  test("the payment admins are emailed once per stage, not per delivery @fresh @membership", async () => {
    const opened = dispute("CHARGEBACK", "OPEN");

    await deliver(opened, "CUSTOMER.DISPUTE.CREATED");
    await deliver(opened, "CUSTOMER.DISPUTE.CREATED"); // redelivery
    await deliver(dispute("CHARGEBACK", "UNDER_REVIEW"), "CUSTOMER.DISPUTE.UPDATED");
    let mail = await capturedMail();
    expect(mail, "an update is not a new notification").toHaveLength(1);
    expect(mail[0].to).toContain(ADMIN_MAIL);
    expect(mail[0].subject).toContain("PayPal dispute opened");

    await deliver(dispute("CHARGEBACK", "RESOLVED", "RESOLVED_BUYER_FAVOUR"), "CUSTOMER.DISPUTE.RESOLVED");
    mail = await capturedMail();
    expect(mail).toHaveLength(2);
    expect(mail[1].subject).toContain("PayPal dispute resolved");
  });
});

/**
 * Missed webhooks. A webhook that never arrived (the site was down, the endpoint
 * was not registered yet) is caught by the hourly missed-payment job, which asks
 * the gateway for the events it should have received and replays them oldest
 * first. A chargeback that depended on a webhook arriving would otherwise be lost.
 */
test.describe("dispute backfill @fresh", () => {
  const plan = useSharedPlan({ type: "paid" });
  let before: Record<string, unknown> | undefined;
  let sub: SeededSubscription | undefined;

  test.beforeEach(async () => {
    before = undefined;
    sub = undefined;
    test.skip(!seedAvailable(), "the runner did not stage the seed endpoint, so backfill state cannot be seeded");
  });

  test.afterEach(async () => {
    if (!seedAvailable()) return;
    await captureMail(false);
    await clearStubs();
    if (before) await restoreOptions(before);
    if (sub) await sub.cleanup();
  });

  const emptyList = { object: "list", data: [], has_more: false, url: "/v1/list" };

  /**
   * @area    membership
   * @tier    fresh
   * @guards  themegrill/user-registration-pro#1717
   * @source  write-spec 2026-10-28
   * @why     A Stripe dispute whose webhook was missed must still revoke access when
   *          the hourly job finds the event on Stripe's side.
   */
  test("the missed-payment job applies a Stripe dispute whose webhook never arrived @fresh @membership", async ({ page }) => {
    await loginAsAdmin(page);
    sub = await seedSubscription(page, {
      gatewaySubscriptionId: STRIPE_SUB,
      paymentMethod: "stripe",
      planId: plan.id,
      status: "active",
      order: { status: "completed", transactionId: STRIPE_PI },
    });
    before = await setOptions({
      user_registration_stripe_enabled: "1",
      user_registration_stripe_test_mode: "1",
      user_registration_stripe_test_secret_key: "sk_test_tgqa",
      user_registration_stripe_test_publishable_key: "pk_test_tgqa",
      ur_membership_payment_gateways: { stripe: true },
      urm_last_missed_payment_events_check_sync_time: 0,
      urm_last_stripe_payment_backfill_sync_time: 0,
    });
    await captureMail(true);
    const missed = {
      id: "evt_tgqa_missed",
      object: "event",
      type: "charge.dispute.created",
      created: Math.floor(Date.now() / 1000) - 3600,
      data: {
        object: { id: "dp_tgqa_missed", object: "dispute", status: "needs_response", reason: "fraudulent", amount: 1000, currency: "usd", payment_intent: STRIPE_PI },
      },
    };
    await setStripeStubs([
      { method: "GET", path: "/v1/events", body: { ...emptyList, data: [missed] } },
      { method: "GET", path: `/v1/subscriptions/${STRIPE_SUB}`, body: { id: STRIPE_SUB, object: "subscription", status: "active" } },
      { method: "DELETE", path: `/v1/subscriptions/${STRIPE_SUB}`, body: { id: STRIPE_SUB, object: "subscription", status: "canceled" } },
      { method: "GET", path: "/v1/subscriptions", body: emptyList },
      { method: "GET", path: "/v1/invoices", body: emptyList },
      { method: "GET", path: "/v1/payment_intents", body: emptyList },
      { method: "GET", path: "/v1/charges", body: emptyList },
      { method: "GET", path: "/v1/refunds", body: emptyList },
    ]);

    const run = await runCron("urm_missed_payment_events_check");

    const unmatched = (await stubCalls()).filter((c) => !c.matched).map((c) => `${c.method} ${c.path}`);
    expect(unmatched, "every Stripe call the job makes must have been stubbed").toEqual([]);
    expect(run).toMatchObject({ ran: true });
    expect((await selectRows(T.orders, { ID: sub.orderId! }))[0].status).toBe("refunded");
    expect((await selectRows(T.subscriptions, { ID: sub.subscriptionId }))[0].status).toBe("canceled");
    expect(serialisedField(await userMeta(sub.userId, "urm_stripe_dispute"), "dispute_id")).toBe("dp_tgqa_missed");
  });

  /**
   * @area    membership
   * @tier    fresh
   * @guards  themegrill/user-registration-pro#1717
   * @source  write-spec 2026-10-28
   * @why     The same safety net for PayPal: a dispute event listed by PayPal's
   *          events API is replayed through the same handler as a webhook.
   */
  test("the missed-payment job applies a PayPal dispute whose webhook never arrived @fresh @membership", async ({ page }) => {
    await loginAsAdmin(page);
    sub = await seedSubscription(page, {
      gatewaySubscriptionId: PAYPAL_SUB,
      paymentMethod: "paypal",
      planId: plan.id,
      status: "active",
      order: { status: "completed", transactionId: PAYPAL_TXN },
    });
    before = await setOptions({
      ...paypalTestOptions,
      ur_membership_payment_gateways: { paypal: true },
      urm_last_missed_payment_events_check_sync_time: 0,
      urm_last_paypal_backfill_sync_time: 0,
      urm_paypal_backfill_seed_pending: 0,
    });
    await captureMail(true);
    const events = (type: string, resource: Record<string, unknown>) => ({
      events: [{ id: `WH-${type}`, event_type: type, create_time: new Date().toISOString(), resource }],
      links: [],
    });
    await stubPaypalVerification("SUCCESS", [
      {
        method: "GET",
        url: `${PAYPAL_API}/v1/notifications/webhooks-events`,
        query: "CUSTOMER.DISPUTE.CREATED",
        body: events("CUSTOMER.DISPUTE.CREATED", {
          dispute_id: "PP-D-MISSED",
          status: "OPEN",
          dispute_life_cycle_stage: "CHARGEBACK",
          reason: "MERCHANDISE_OR_SERVICE_NOT_RECEIVED",
          dispute_amount: { value: "10.00", currency_code: "USD" },
          disputed_transactions: [{ seller_transaction_id: PAYPAL_TXN }],
        }),
      },
      { method: "GET", url: `${PAYPAL_API}/v1/notifications/webhooks-events`, body: { events: [], links: [] } },
      { method: "POST", url: `${PAYPAL_API}/v1/billing/subscriptions/${PAYPAL_SUB}/cancel`, status: 204 },
    ]);

    const run = await runCron("urm_missed_payment_events_check");

    const unmatched = (await stubCalls()).filter((c) => !c.matched).map((c) => `${c.method} ${c.path}`);
    expect(unmatched, "every PayPal call the job makes must have been stubbed").toEqual([]);
    expect(run).toMatchObject({ ran: true });
    expect((await selectRows(T.orders, { ID: sub.orderId! }))[0].status).toBe("refunded");
    expect((await selectRows(T.subscriptions, { ID: sub.subscriptionId }))[0].status).toBe("canceled");
    expect(serialisedField(await userMeta(sub.userId, "urm_paypal_dispute"), "dispute_id")).toBe("PP-D-MISSED");
  });
});
