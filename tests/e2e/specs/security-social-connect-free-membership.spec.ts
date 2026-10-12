import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Assign the default Free membership to opted-in Social Connect signups (isolated PHP) @fresh @membership", async () => {
  await runSecurityRegression("social-connect-free-membership", 21);
});
