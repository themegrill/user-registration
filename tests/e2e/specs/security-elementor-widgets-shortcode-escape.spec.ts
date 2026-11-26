import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Escape shortcodes in Elementor widget output (isolated PHP) @fresh @security", async () => {
  await runSecurityRegression("elementor-widgets-shortcode-escape", 12);
});
