import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Discard a declined Stripe registration's pending member only for its own session (isolated PHP) @fresh @membership", async () => {
  await runSecurityRegression("stripe-discard-pending-member", 6);
});
