import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Escape shortcodes in Oxygen widget output (isolated PHP) @fresh @security", async () => {
  await runSecurityRegression("oxygen-widgets-shortcode-escape", 12);
});
