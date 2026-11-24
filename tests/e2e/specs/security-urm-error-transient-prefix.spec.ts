import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Restrict ?urm_error= transient lookups to ur_login_error_ prefix (isolated PHP) @fresh @security", async () => {
  await runSecurityRegression("urm-error-transient-prefix", 24);
});
