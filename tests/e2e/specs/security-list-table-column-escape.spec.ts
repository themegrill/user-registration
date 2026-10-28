import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Escape custom column values in the users and members lists (isolated PHP) @fresh @security", async () => {
  await runSecurityRegression("list-table-column-escape", 4);
});
