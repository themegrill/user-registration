import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Fix comments on restricted content readable through the REST API (isolated PHP) @fresh @security", async () => {
  await runSecurityRegression("rest-comment-restriction", 14);
});
