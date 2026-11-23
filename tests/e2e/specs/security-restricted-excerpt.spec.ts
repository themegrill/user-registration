import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Fix restricted post excerpts leaking on archives and the empty profile completeness gate (isolated PHP) @fresh @security @content-restriction", async () => {
  await runSecurityRegression("restricted-excerpt", 8);
});
