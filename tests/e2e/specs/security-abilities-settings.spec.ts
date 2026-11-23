import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("AI settings have their own Advanced section with the master switch, and other parts add cards to it (isolated PHP) @fresh @security", async () => {
	await runSecurityRegression("abilities-settings", 13);
});
