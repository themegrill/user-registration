import { expect, test } from "@playwright/test";
import {
  paypalTestOptions,
  postPaypalWebhook,
  postStripeWebhook,
  seedSubscription,
  serialisedField,
  stubPaypalVerification,
  T,
  userMeta,
  type SeededSubscription,
} from "../support/payments";
import { clearStubs, restoreOptions, seedAvailable, selectRows, setOptions, setStripeStubs, stubCalls } from "../support/seed";
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

test.describe("stripe disputes @fresh", () => {
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
      planType: "paid",
      status: "active",
      order: { status: "completed", transactionId: STRIPE_PI },
    });
    before = await setOptions({
      user_registration_stripe_enabled: "1",
      user_registration_stripe_test_mode: "1",
      user_registration_stripe_test_secret_key: "sk_test_tgqa",
      user_registration_stripe_test_publishable_key: "pk_test_tgqa",
      user_registration_stripe_webhook_secret_test: STRIPE_SECRET,
    });
  });

  test.afterEach(async () => {
    if (!seedAvailable()) return;
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
});

test.describe("paypal disputes @fresh", () => {
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
      planType: "paid",
      status: "active",
      order: { status: "completed", transactionId: PAYPAL_TXN },
    });
    before = await setOptions({ ...paypalTestOptions });
    await stubPaypalVerification("SUCCESS");
  });

  test.afterEach(async () => {
    if (!seedAvailable()) return;
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
});
