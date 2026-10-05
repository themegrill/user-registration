import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Explain why an addon cannot be installed instead of a generic download error (isolated PHP) @fresh @security", async () => {
	await runSecurityRegression("addon-package-error", 7);
});
