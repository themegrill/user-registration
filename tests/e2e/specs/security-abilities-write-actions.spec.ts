import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Every AI ability that changes data is refused without permission, hidden by its switch, strict about input and confirmed when risky (isolated PHP) @fresh @security", async () => {
	await runSecurityRegression("abilities-write-actions", 277);
});
