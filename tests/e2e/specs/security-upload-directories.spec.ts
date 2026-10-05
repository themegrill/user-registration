import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Harden public upload directories against non-image file serving (isolated PHP) @fresh @security", async () => {
  await runSecurityRegression("upload-directories", 43);
});
