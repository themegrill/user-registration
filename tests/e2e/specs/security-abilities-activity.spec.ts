import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Every AI action is written to the plugin log without member details (isolated PHP) @fresh @security", async () => {
	await runSecurityRegression("abilities-activity", 24);
});
