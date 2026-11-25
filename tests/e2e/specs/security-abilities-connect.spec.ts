import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("One click makes the AI user and its password and gives each AI app what it needs, with a check of what is ready (isolated PHP) @fresh @security", async () => {
	await runSecurityRegression("abilities-connect", 37);
});
