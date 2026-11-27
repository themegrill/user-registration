import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Fix - Reject a Stripe PaymentIntent that paid less than the one-time order total (isolated PHP) @fresh @security", async () => {
  await runSecurityRegression("stripe-intent-amount", 9);
});
