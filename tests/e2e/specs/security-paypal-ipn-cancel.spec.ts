import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("A PayPal cancel IPN only cancels a membership it belongs to (isolated PHP) @fresh @security", async () => {
  await runSecurityRegression("paypal-ipn-cancel", 19);
});
