import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Fix restricted Elementor section text leaking through REST and feed excerpts (isolated PHP) @fresh @security @content-restriction", async () => {
  await runSecurityRegression("elementor-section-excerpt", 26);
});
