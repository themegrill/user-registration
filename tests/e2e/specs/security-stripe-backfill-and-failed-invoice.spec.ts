import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Fix - Backfill paid Stripe renewals on current API versions and keep the member's status while Stripe retries a failed invoice (isolated PHP) @fresh @security", async () => {
  await runSecurityRegression("stripe-backfill-and-failed-invoice", 47);
});
