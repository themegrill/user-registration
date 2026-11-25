import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Rate limit registration submissions per client IP (isolated PHP) @fresh @security", async () => {
  await runSecurityRegression("registration-rate-limit", 68);
});
