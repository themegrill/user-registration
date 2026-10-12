import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Limit CAPTCHA and login settings writes to declared fields (isolated PHP) @fresh @security", async () => {
  await runSecurityRegression("captcha-options", 26);
});
