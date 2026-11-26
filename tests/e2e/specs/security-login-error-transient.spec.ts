import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Login errors only read the plugin's own transients and the pending-member token is random (isolated PHP) @fresh @membership", async () => {
  await runSecurityRegression("login-error-transient", 19);
});
