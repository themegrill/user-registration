import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Fix stored XSS from checkbox values saved on the profile page (isolated PHP) @fresh @security", async () => {
  await runSecurityRegression("checkbox-profile-update", 14);
});
