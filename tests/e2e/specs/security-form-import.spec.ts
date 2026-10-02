import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Restrict imported post fields to new registration forms (isolated PHP) @fresh @security", async () => {
  await runSecurityRegression("form-import", 14);
});
