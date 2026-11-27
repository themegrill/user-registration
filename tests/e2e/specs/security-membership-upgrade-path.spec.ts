import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Check the upgrade path against the owned subscription, not a forged plan ID (isolated PHP) @fresh @membership", async () => {
  await runSecurityRegression("membership-upgrade-path", 8);
});
