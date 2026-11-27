import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Stripe upgrade cancels the replaced plan and keeps the new one on old-charge refunds (isolated PHP) @fresh @security", async () => {
  await runSecurityRegression("stripe-upgrade-old-sub-cancel", 41);
});
