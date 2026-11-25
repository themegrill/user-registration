import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Honor forwarded client IP headers only from trusted proxies (isolated PHP) @fresh @security", async () => {
  await runSecurityRegression("trusted-proxy-ip", 21);
});
