import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Fix CSRF protection for bulk user status actions (isolated PHP) @fresh @security", async () => {
  await runSecurityRegression("bulk-status", 31);
});
