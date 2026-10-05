import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Locked settings cards show the add-on name in every license state (isolated PHP) @fresh @admin", async () => {
  await runSecurityRegression("locked-card-title", 9);
});
