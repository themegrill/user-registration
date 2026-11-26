import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Rate limit plugin login endpoints per account-IP pair and per IP with atomic checks (isolated PHP) @fresh @security", async () => {
  await runSecurityRegression("login-rate-limit", 59);
});
