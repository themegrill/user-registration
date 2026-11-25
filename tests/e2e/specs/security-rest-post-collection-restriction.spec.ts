import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Fix restricted post counts disclosed by REST collection headers (isolated PHP) @fresh @security", async () => {
  await runSecurityRegression("rest-post-collection-restriction", 7);
});
