import { expect, test } from "@playwright/test";
import { memberMembershipStatus, postStripeWebhook, seedSubscription, stubEvent, T, useSharedPlan, type SeededSubscription } from "../support/payments";
import { clearStubs, restoreOptions, requireSeed, seedAvailable, selectRows, setOptions, stubCalls } from "../support/seed";
import { loginAsAdmin } from "../support/wp";

/**
 * Stripe renewal invoices (`invoice.payment_succeeded`) against a paid-up
 * subscription.
 *
 * The product used to stamp a new billing cycle from TODAY on every delivery, so
 * Stripe redelivering an event, or a Dashboard "Resend", gave the member another
 * cycle without a second payment. The dates now come from the billing period on
 * the invoice and only ever move forward. The isolated PHP regression covers the
 * handler's branches against stubs; these specs run the real handler, through the
 * real signed webhook route, against real rows.
 *
 * Dates are day-granular in the handler, so every date here is a UTC midnight.
 */
const SECRET = "whsec_tgqa_renewal";
const SUB = "sub_tgqa_renewal";
const DAY = 86_400;
const PAID_PI = "pi_tgqa_cycle_1";

const midnight = (daysFromToday: number) => {
  const t = new Date();
  return Math.floor(Date.UTC(t.getUTCFullYear(), t.getUTCMonth(), t.getUTCDate()) / 1000) + daysFromToday * DAY;
};
const sqlDate = (ts: number) => new Date(ts * 1000).toISOString().slice(0, 10) + " 00:00:00";

test.describe("stripe renewal invoice @fresh", () => {
  const plan = useSharedPlan({ type: "paid", billingTerms: { value: 1, duration: "month" } });
  // The cycle the member has already paid for: it began 20 days ago and ends in 10.
  const CYCLE_START = midnight(-20);
  const CYCLE_END = midnight(10);

  let before: Record<string, unknown> | undefined;
  let sub: SeededSubscription | undefined;

  test.beforeEach(async ({ page }) => {
    before = undefined;
    sub = undefined;
    requireSeed("the runner did not stage the seed endpoint, so renewal state cannot be seeded");
    await loginAsAdmin(page);
    sub = await seedSubscription(page, {
      planId: plan.id,
      gatewaySubscriptionId: SUB,
      paymentMethod: "stripe",
      status: "active",
      startDate: sqlDate(CYCLE_START),
      expiryDate: sqlDate(CYCLE_END),
      order: { status: "completed", transactionId: PAID_PI },
    });
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

  const renewal = (id: string, paymentIntent: string, periodStart: number, periodEnd: number) => ({
    id: `evt_${id}`,
    type: "invoice.payment_succeeded",
    data: {
      object: {
        id: `in_${id}`,
        object: "invoice",
        subscription: SUB,
        payment_intent: paymentIntent,
        amount_paid: 1000,
        lines: { data: [{ period: { start: periodStart, end: periodEnd } }] },
      },
    },
  });

  const deliver = async (event: ReturnType<typeof renewal>) => {
    await stubEvent(event);
    const res = await postStripeWebhook(event, SECRET);
    expect(res.status, res.text).toBe(200);
  };
  const row = async () => (await selectRows(T.subscriptions, { ID: sub!.subscriptionId }))[0];
  const orders = async () => selectRows(T.orders, { subscription_id: sub!.subscriptionId });

  /**
   * @area    membership
   * @tier    fresh
   * @guards  themegrill/user-registration-pro#1910
   * @source  write-spec 2026-10-28
   * @why     Stripe redelivers events, and the Dashboard can Resend one. Before the
   *          fix each delivery of an already-processed renewal invoice moved the
   *          expiry to today plus one cycle, so the member got a further cycle
   *          without paying for it. A redelivery must leave the dates and the
   *          orders exactly as they were.
   */
  test("a redelivered renewal invoice does not extend the membership again @fresh @membership", async ({ browser }) => {
    const paidInvoice = renewal("replay", PAID_PI, CYCLE_START, CYCLE_END);
    const member = await memberMembershipStatus(browser, sub!);
    const shownBefore = await member.nextBilling();
    expect(Date.parse(shownBefore + " UTC"), "the member sees the paid cycle's end").toBe(CYCLE_END * 1000);

    await deliver(paidInvoice);
    await deliver(paidInvoice); // the Dashboard's "Resend"

    try {
      expect(await member.nextBilling(), "the member's next billing date must not move on a redelivery").toBe(shownBefore);
    } finally {
      await member.close();
    }

    const r = await row();
    expect(r.expiry_date, "the expiry must stay where the paid cycle ends").toBe(sqlDate(CYCLE_END));
    expect(r.start_date).toBe(sqlDate(CYCLE_START));
    expect(await orders(), "a redelivery must not create a second order").toHaveLength(1);
    expect((await stubCalls()).filter((c) => !c.matched), "every Stripe call must have been stubbed").toEqual([]);
  });

  /**
   * @area    membership
   * @tier    fresh
   * @guards  themegrill/user-registration-pro#1910
   * @source  write-spec 2026-10-28
   * @why     The fix must not stop genuine renewals: an invoice with a new payment
   *          for the next cycle extends the membership to the end of THAT invoice's
   *          billing period (not to today plus a cycle) and records one new order.
   */
  test("a genuine next-cycle renewal extends to the invoice's period end @fresh @membership", async ({ browser }) => {
    const nextEnd = CYCLE_END + 30 * DAY;
    const member = await memberMembershipStatus(browser, sub!);

    await deliver(renewal("next", "pi_tgqa_cycle_2", CYCLE_END, nextEnd));

    try {
      expect(Date.parse((await member.nextBilling()) + " UTC"), "the member sees the new cycle's end").toBe(nextEnd * 1000);
    } finally {
      await member.close();
    }

    const r = await row();
    expect(r.expiry_date).toBe(sqlDate(nextEnd));
    expect(r.start_date).toBe(sqlDate(CYCLE_END));
    expect(r.status).toBe("active");
    expect(await orders(), "the renewal's payment is recorded once").toHaveLength(2);
  });

  /**
   * @area    membership
   * @tier    fresh
   * @guards  themegrill/user-registration-pro#1910
   * @source  write-spec 2026-10-28
   * @why     Stripe does not guarantee delivery order. An older invoice arriving
   *          after a later renewal still records its payment, but must not roll the
   *          subscription back to an earlier end date.
   */
  test("an older invoice delivered late records its payment but never moves the dates back @fresh @membership", async ({ browser }) => {
    const member = await memberMembershipStatus(browser, sub!);
    const shownBefore = await member.nextBilling();

    await deliver(renewal("older", "pi_tgqa_cycle_0", CYCLE_START - 30 * DAY, CYCLE_START));

    try {
      expect(await member.nextBilling(), "a late old invoice must not move the member's date back").toBe(shownBefore);
    } finally {
      await member.close();
    }

    const r = await row();
    expect(r.expiry_date, "an earlier period must not shorten the membership").toBe(sqlDate(CYCLE_END));
    expect(r.start_date).toBe(sqlDate(CYCLE_START));
    expect(await orders(), "its payment is still recorded").toHaveLength(2);
  });
});
