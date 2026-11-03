import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Fix members waiting for admin approval staying logged in on page load (isolated PHP) @fresh @security", async () => {
  await runSecurityRegression("approval-check-on-page", 9);
});
