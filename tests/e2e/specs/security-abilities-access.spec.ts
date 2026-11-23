import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("AI abilities are hidden above the access chosen for each area, and the settings come from the areas (isolated PHP) @fresh @security", async () => {
	await runSecurityRegression("abilities-access", 32);
});
