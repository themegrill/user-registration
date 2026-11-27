import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Fix - Reject subscription reactivation when the payment gateway refuses it (isolated PHP) @fresh @security", async () => {
  await runSecurityRegression("subscription-reactivation-gateway", 8);
});
