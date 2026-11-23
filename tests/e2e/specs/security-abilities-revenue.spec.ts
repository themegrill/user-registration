import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("The AI revenue summary counts completed payments only and keeps other statuses apart (isolated PHP) @fresh @security", async () => {
	await runSecurityRegression("abilities-revenue", 22);
});
