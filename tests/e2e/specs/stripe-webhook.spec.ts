import { expect, test } from "@playwright/test";
import { postStripeWebhook, seedSubscription, stubEvent, T, type SeededSubscription } from "../support/payments";
import { clearStubs, restoreOptions, seedAvailable, selectRows, setOptions, stubCalls } from "../support/seed";
import { loginAsAdmin } from "../support/wp";

/**
 * Stripe webhooks, delivered signed.
 *
 * The signature is computed with the secret the test seeds, so the product's own
 * `Webhook::constructEvent` runs for real and a wrong secret is refused by it. The
 * handler then re-fetches the event from Stripe by id; that call is answered from
 * a stub and Stripe is never contacted.
 */
const SECRET = "whsec_tgqa";
const SUB = "sub_tgqa_webhook";

test.describe("stripe webhook @fresh", () => {
  let before: Record<string, unknown> | undefined;
  let sub: SeededSubscription | undefined;

  test.beforeEach(async ({ page }) => {
    before = undefined;
    sub = undefined;
    test.skip(!seedAvailable(), "the runner did not stage the seed endpoint, so webhook state cannot be seeded");
    await loginAsAdmin(page);
    sub = await seedSubscription(page, { gatewaySubscriptionId: SUB, paymentMethod: "stripe", status: "active" });
    before = await setOptions({
      user_registration_stripe_enabled: "1",
      user_registration_stripe_test_mode: "1",
      user_registration_stripe_test_secret_key: "sk_test_tgqa",
      user_registration_stripe_test_publishable_key: "pk_test_tgqa",
      user_registration_stripe_webhook_secret_test: SECRET,
    });
  });

  test.afterEach(async () => {
    if (!seedAvailable()) return;
    await clearStubs();
    if (before) await restoreOptions(before);
    if (sub) await sub.cleanup();
  });

  const failedInvoice = () => ({
    id: "evt_tgqa_webhook_1",
    type: "invoice.payment_failed",
    // No next_payment_attempt: Stripe has stopped retrying, so the site must act.
    data: { object: { id: "in_tgqa_1", subscription: SUB, next_payment_attempt: null } },
  });
  const statusOf = async () => (await selectRows(T.subscriptions, { ID: sub!.subscriptionId }))[0].status;

  /**
   * @area    membership
   * @tier    fresh
   * @source  write-spec 2026-10-28
   * @why     When Stripe reports a failed invoice and will not retry, the member
   *          must leave "active" so the site stops treating an unpaid subscription
   *          as paid. When Stripe IS still retrying the status is kept, which this
   *          does not cover.
   */
  test("a signed failed-invoice event moves the subscription to pending @fresh @membership", async () => {
    const event = failedInvoice();
    await stubEvent(event);

    const res = await postStripeWebhook(event, SECRET);

    expect(res.status, res.text).toBe(200);
    expect(await statusOf()).toBe("pending");
    const calls = await stubCalls();
    expect(calls.map((c) => `${c.method} ${c.path}`)).toContain(`GET /v1/events/${event.id}`);
    expect(calls.filter((c) => !c.matched), "every Stripe call must have been stubbed").toEqual([]);
  });

  /**
   * @area    membership
   * @tier    fresh
   * @source  write-spec 2026-10-28
   * @why     A request not signed with the site's secret must be refused before
   *          anything is read or written; otherwise anyone who can reach the
   *          webhook URL could cancel or downgrade a member.
   */
  test("an event signed with the wrong secret is refused and changes nothing @fresh @membership", async () => {
    const event = failedInvoice();
    await stubEvent(event);

    const res = await postStripeWebhook(event, "whsec_not_the_sites");

    expect(res.status).toBe(401);
    expect(await statusOf()).toBe("active");
    expect(await stubCalls(), "a refused request must not reach Stripe").toEqual([]);
  });
});
