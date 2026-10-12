import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Send the reminder that matches the renewal behaviour a subscription was created under (isolated PHP) @fresh @membership", async () => {
  await runSecurityRegression("renewal-behaviour", 11);
});
