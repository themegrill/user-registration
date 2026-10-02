import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Fix - Resolve the PaymentIntent of a paid Stripe renewal on API versions without invoice.payment_intent (isolated PHP) @fresh @security", async () => {
  await runSecurityRegression("stripe-invoice-payment-intent", 15);
});
