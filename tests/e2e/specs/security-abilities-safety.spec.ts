import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("A risky AI ability shows a preview before it runs once with a single-use code (isolated PHP) @fresh @security", async () => {
	await runSecurityRegression("abilities-safety", 25);
});
