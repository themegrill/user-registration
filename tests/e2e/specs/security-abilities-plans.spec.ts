import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("AI can read, create and change membership plans and plan groups through the same save code as the screens, with the editor's limits (isolated PHP) @fresh @security", async () => {
	await runSecurityRegression("abilities-plans", 69);
});
