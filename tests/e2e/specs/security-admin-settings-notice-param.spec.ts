import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Escape the ur_error and ur_message settings notices (isolated PHP) @fresh @security", async () => {
  await runSecurityRegression("admin-settings-notice-param", 4);
});
