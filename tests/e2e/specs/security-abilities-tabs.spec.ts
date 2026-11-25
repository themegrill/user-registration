import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("The AI Integration tabs only change the layout, change no setting, and turn off with one filter (isolated PHP) @fresh @security", async () => {
	await runSecurityRegression("abilities-tabs", 19);
});
