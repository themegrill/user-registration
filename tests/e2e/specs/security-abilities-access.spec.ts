import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Each AI ability has its own switch that follows the area level until saved, and every area gets a card of switches (isolated PHP) @fresh @security", async () => {
	await runSecurityRegression("abilities-access", 40);
});
