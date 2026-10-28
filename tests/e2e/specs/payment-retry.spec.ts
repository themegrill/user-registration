import { expect, test } from "@playwright/test";
import { seedSubscription, T, type SeededSubscription } from "../support/payments";
import {
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
 * Failed-payment retry (dunning) for Stripe subscriptions.
 *
 * The daily `urm_daily_payment_retry_check` job is the only thing that retries a
 * subscription whose renewal failed, and a click cannot reach it: it needs an
 * expired subscription row, a Stripe account to ask, and the clock. All three are
 * seeded over the claudegrill seed endpoint, and the job is run directly instead
 * of waiting for WP-Cron, which Playground does not have.
 *
 * Stripe is never contacted. The SDK is answered from stubs for the length of the
 * test, and a call nobody stubbed is reported as `matched: false` and fails the
 * assertion on the call log rather than going to the network.
 */
const SUB = "sub_tgqa_retry";
const stripeSubscription = (status: string) => ({
  id: SUB,
  object: "subscription",
  status,
  default_payment_method: "pm_tgqa",
});

test.describe("stripe payment retry @fresh", () => {
  let before: Record<string, unknown> | undefined;
  let sub: SeededSubscription | undefined;

  test.beforeEach(async ({ page }) => {
    // Per-test state, so one test's teardown never restores another's leftovers.
    before = undefined;
    sub = undefined;
    test.skip(!seedAvailable(), "the runner did not stage the seed endpoint, so retry state cannot be seeded");
    await loginAsAdmin(page);
  });

  test.afterEach(async () => {
    if (!seedAvailable()) return;
    await clearStubs();
    if (before) await restoreOptions(before);
    if (sub) await sub.cleanup();
  });

  /** Gateway and retry settings, and an expired Stripe subscription due for a retry. */
  async function arrange(page: import("@playwright/test").Page, retries: number) {
    // The plan first: creating a plan on a site with Stripe enabled makes the
    // product sync a price with Stripe.
    sub = await seedSubscription(page, { status: "expired", gatewaySubscriptionId: SUB, paymentMethod: "stripe" });
    before = await setOptions({
      user_registration_stripe_enabled: "1",
      user_registration_stripe_test_mode: "1",
      user_registration_stripe_test_secret_key: "sk_test_tgqa",
      user_registration_stripe_test_publishable_key: "pk_test_tgqa",
      user_registration_payment_retry_enabled: "yes",
      user_registration_payment_retry_count: String(retries),
      user_registration_payment_retry_interval: "1",
    });
  }

  const statusOf = async () => (await selectRows(T.subscriptions, { ID: sub!.subscriptionId }))[0].status;

  /**
   * @area    membership
   * @tier    fresh
   * @source  write-spec 2026-10-28
   * @why     Stripe keeps billing a subscription the site marked expired when the
   *          member's card recovers after the site's own retry window opens. The
   *          retry job must read Stripe's answer and bring the local row back to
   *          active, not leave a paying member locked out.
   */
  test("a recovered Stripe subscription is brought back to active @fresh @membership", async ({ page }) => {
    await arrange(page, 3);
    await setStripeStubs([{ method: "GET", path: `/v1/subscriptions/${SUB}`, body: stripeSubscription("active") }]);

    const run = await runCron("urm_daily_payment_retry_check");
    expect(run, "the retry job should run without throwing").toMatchObject({ ran: true });

    const calls = await stubCalls();
    expect(calls.filter((c) => !c.matched), "every Stripe call must have been stubbed").toEqual([]);
    expect(calls.map((c) => `${c.method} ${c.path}`)).toContain(`GET /v1/subscriptions/${SUB}`);
    expect(await statusOf()).toBe("active");
  });

  /**
   * @area    membership
   * @tier    fresh
   * @source  write-spec 2026-10-28
   * @why     While Stripe is still dunning (past_due) the site must keep trying and
   *          must not give up: the row stays expired and no cancellation happens
   *          before the configured number of retries has been used.
   */
  test("a subscription Stripe still reports past due is retried, not cancelled @fresh @membership", async ({ page }) => {
    await arrange(page, 3);
    await setStripeStubs([
      { method: "GET", path: `/v1/subscriptions/${SUB}`, body: stripeSubscription("past_due") },
      { method: "POST", path: `/v1/subscriptions/${SUB}`, body: stripeSubscription("past_due") },
    ]);

    await runCron("urm_daily_payment_retry_check");

    const calls = await stubCalls();
    expect(calls.filter((c) => !c.matched), "every Stripe call must have been stubbed").toEqual([]);
    expect(calls.map((c) => `${c.method} ${c.path}`)).toContain(`POST /v1/subscriptions/${SUB}`);
    expect(await statusOf()).toBe("expired");
  });

  /**
   * @area    membership
   * @tier    fresh
   * @source  write-spec 2026-10-28
   * @why     Retries must end. With the limit set to one, the first daily run
   *          retries and the second finds the limit used and asks Stripe to cancel
   *          the subscription at the end of the paid period; without that, an
   *          unrecoverable card is retried every day for as long as the row stays
   *          inside the window.
   *
   *          Asserts the request to Stripe, not the local status: the product
   *          cancels at period end, so the row stays as it is until then and
   *          holds a pending-cancel marker instead. That is the product's design,
   *          so a spec expecting `canceled` straight away would be wrong.
   */
  test("the retry limit ends in a cancel request to Stripe @fresh @membership", async ({ page }) => {
    await arrange(page, 1);
    await setStripeStubs([
      { method: "GET", path: `/v1/subscriptions/${SUB}`, body: stripeSubscription("past_due") },
      { method: "POST", path: `/v1/subscriptions/${SUB}`, body: stripeSubscription("past_due") },
    ]);
    const cancels = async () =>
      (await stubCalls()).filter((c) => (c.params as Record<string, unknown>)?.cancel_at_period_end !== undefined);

    await runCron("urm_daily_payment_retry_check");
    expect(await cancels(), "the first run is a retry, not a cancellation").toHaveLength(0);

    await runCron("urm_daily_payment_retry_check");
    const asked = await cancels();
    expect(asked, "the second run finds the limit used and cancels").toHaveLength(1);
    expect(asked[0]).toMatchObject({ method: "POST", path: `/v1/subscriptions/${SUB}`, params: { cancel_at_period_end: "true" } });
    expect((await stubCalls()).filter((c) => !c.matched), "every Stripe call must have been stubbed").toEqual([]);
  });
});
