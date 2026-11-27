import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("AI assistants list modules and addons and switch them as far as the plan allows, never installing or touching payment gateways (isolated PHP) @fresh @security", async () => {
	await runSecurityRegression("abilities-modules", 43);
});
