import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Sanitise the user biography on every save path (isolated PHP) @fresh @security", async () => {
  await runSecurityRegression("user-description-sanitize", 4);
});
