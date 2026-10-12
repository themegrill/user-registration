import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Constrain public nonce refresh to supported actions and forms (isolated PHP) @fresh @security", async () => {
  await runSecurityRegression("nonce-refresh", 15);
});
