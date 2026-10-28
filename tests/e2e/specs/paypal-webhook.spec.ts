import { expect, test } from "@playwright/test";
import {
  paypalCustomId,
  paypalTestOptions,
  postPaypalWebhook,
  seedSubscription,
  stubPaypalVerification,
  T,
  useSharedPlan,
  type SeededSubscription,
} from "../support/payments";
import { clearStubs, restoreOptions, requireSeed, seedAvailable, selectRows, setOptions, stubCalls } from "../support/seed";
import { loginAsAdmin } from "../support/wp";

/**
 * PayPal subscription webhooks.
 *
 * A PayPal delivery is acted on only after the product asks PayPal whether it is
 * genuine. That question and its OAuth token go through WordPress's HTTP API, so
 * both are answered from stubs here and PayPal is never contacted. The signature
 * scheme itself is PayPal's and is not tested; what is tested is what the product
 * does with each answer.
 */
const PAYPAL_SUB = "I-TGQAPAYPAL1";

test.describe("paypal webhook @fresh", () => {
  const plan = useSharedPlan({ type: "free" });
  let before: Record<string, unknown> | undefined;
  let sub: SeededSubscription | undefined;

  test.beforeEach(async ({ page }) => {
    before = undefined;
    sub = undefined;
    requireSeed("the runner did not stage the seed endpoint, so webhook state cannot be seeded");
    await loginAsAdmin(page);
    sub = await seedSubscription(page, { planId: plan.id, gatewaySubscriptionId: PAYPAL_SUB, paymentMethod: "paypal", status: "active" });
    before = await setOptions({ ...paypalTestOptions });
  });

  test.afterEach(async () => {
    if (!seedAvailable()) return;
    await clearStubs();
    if (before) await restoreOptions(before);
    if (sub) await sub.cleanup();
  });

  const failedPayment = () => ({
    id: "WH-EVT-TGQA-1",
    event_type: "BILLING.SUBSCRIPTION.PAYMENT.FAILED",
    resource: { id: PAYPAL_SUB, custom_id: paypalCustomId(sub!) },
  });
  const statusOf = async () => (await selectRows(T.subscriptions, { ID: sub!.subscriptionId }))[0].status;

  /**
   * @area    membership
   * @tier    fresh
   * @source  write-spec 2026-10-28
   * @why     A failed PayPal renewal must take the member out of "active" so the
   *          site stops treating an unpaid subscription as paid. The row moves to
   *          pending, which is what the daily retry job then picks up.
   */
  test("a verified failed-payment event moves the subscription to pending @fresh @membership", async () => {
    await stubPaypalVerification("SUCCESS");

    const res = await postPaypalWebhook(failedPayment());

    expect(res.status, res.text).toBe(200);
    expect(await statusOf()).toBe("pending");
    expect((await stubCalls()).filter((c) => !c.matched), "every PayPal call must have been stubbed").toEqual([]);
  });

  /**
   * @area    membership
   * @tier    fresh
   * @source  write-spec 2026-10-28
   * @why     A delivery PayPal does not vouch for must change nothing. If it did,
   *          anyone who could reach the webhook URL could cancel or downgrade any
   *          member by guessing a row id.
   */
  test("an event PayPal does not verify is refused and changes nothing @fresh @membership", async () => {
    await stubPaypalVerification("FAILURE");

    const res = await postPaypalWebhook(failedPayment());

    expect(res.status).toBe(401);
    expect(await statusOf()).toBe("active");
    expect(
      (await stubCalls()).map((c) => c.path.replace("https://api-m.sandbox.paypal.com", "")),
      "the product must have asked PayPal before refusing",
    ).toContain("/v1/notifications/verify-webhook-signature");
  });
});
