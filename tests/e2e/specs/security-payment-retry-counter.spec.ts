import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Clear the payment retry counter on renewal and send the retry-failed email on every attempt (isolated PHP) @fresh @membership", async () => {
  await runSecurityRegression("payment-retry-counter", 8);
});
