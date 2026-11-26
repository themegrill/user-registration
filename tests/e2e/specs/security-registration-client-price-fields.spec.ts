import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Fix - Never take team_id or upgrade price keys from the browser at registration (isolated PHP) @fresh @security", async () => {
  await runSecurityRegression("registration-client-price-fields", 8);
});
