import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("The AI user holds no admin rights and is lent only what a switched-on ability asks for while it runs (isolated PHP) @fresh @security", async () => {
	await runSecurityRegression("abilities-role", 30);
});
