import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("The pending-member token is random and server-stored (isolated PHP) @fresh @membership", async () => {
  await runSecurityRegression("login-error-transient", 8);
});
