import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Refuse a second membership outside every allow-multiple group (isolated PHP) @fresh @membership", async () => {
  await runSecurityRegression("membership-multiple-purchase", 7);
});
