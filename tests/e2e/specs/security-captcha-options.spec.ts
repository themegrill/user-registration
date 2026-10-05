import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Limit CAPTCHA settings writes to declared section fields (isolated PHP) @fresh @security", async () => {
  await runSecurityRegression("captcha-options", 15);
});
