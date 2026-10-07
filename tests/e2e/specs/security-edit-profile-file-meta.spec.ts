import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Opening a member's edit screen does not rewrite the admin's file meta (isolated PHP) @fresh @security", async () => {
  await runSecurityRegression("edit-profile-file-meta", 9);
});
