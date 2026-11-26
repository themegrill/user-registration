import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Each area groups its AI abilities under a few switches that follow the area level until saved, all in one card (isolated PHP) @fresh @security", async () => {
	await runSecurityRegression("abilities-access", 26);
});
