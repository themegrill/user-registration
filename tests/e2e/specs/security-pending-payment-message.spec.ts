import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Fix login critical error when the pending payment message contains a percent sign (isolated PHP) @fresh @security", async () => {
  await runSecurityRegression("pending-payment-message", 6);
});
