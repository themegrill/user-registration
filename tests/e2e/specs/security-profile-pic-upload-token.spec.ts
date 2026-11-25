import { test } from "@playwright/test";
import { runSecurityRegression } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Authenticate profile-picture upload tokens and confine moves to temp-uploads (isolated PHP) @fresh @security", async () => {
  await runSecurityRegression("profile-pic-upload-token", 27);
});
