import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("A redelivered Stripe renewal invoice does not extend the membership again (isolated PHP) @fresh @membership", async () => {
  await runSecurityRegression("stripe-replayed-invoice", 31);
});
