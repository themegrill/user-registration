import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("AI abilities share one registration that addons extend, skips broken definitions and names the plan a locked area needs (isolated PHP) @fresh @security", async () => {
	await runSecurityRegression("abilities-registry", 28);
});
