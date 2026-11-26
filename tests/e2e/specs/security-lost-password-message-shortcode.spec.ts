import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Escape shortcodes in the lost-password error message (isolated PHP) @fresh @security", async () => {
  await runSecurityRegression("lost-password-message-shortcode", 17);
});
