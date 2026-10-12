import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Load the Members view for a member whose invoices were saved nested (isolated PHP) @fresh @membership", async () => {
  await runSecurityRegression("payment-invoices-nested", 8);
});
