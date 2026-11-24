import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Keep required fields required unless the field has conditional logic (isolated PHP) @fresh @security", async () => {
	await runSecurityRegression("urcl-hide-fields", 7);
});
