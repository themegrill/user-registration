import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Block profile-save password bypass without current password (isolated PHP) @fresh @security", async () => {
  await runSecurityRegression("profile-save-password-bypass", 12);
});
