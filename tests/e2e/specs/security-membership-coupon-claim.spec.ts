import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Hold a coupon's usage limit on registration, extra plan, upgrade and scheduled-upgrade cancel (isolated PHP) @fresh @membership", async () => {
  await runSecurityRegression("membership-coupon-claim", 16);
});
