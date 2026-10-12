import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Open the membership checkout for every renew, upgrade and purchase link (isolated PHP) @fresh @membership", async () => {
  await runSecurityRegression("membership-checkout-links", 8);
});
