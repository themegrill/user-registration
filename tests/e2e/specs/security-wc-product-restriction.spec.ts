import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Let content rules restrict WooCommerce product purchase (isolated PHP) @fresh @content-restriction", async () => {
  await runSecurityRegression("wc-product-restriction", 19);
});
