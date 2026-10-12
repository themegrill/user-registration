import { test } from "@playwright/test";
import { runSecurityRegression, withWordPressCore } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Keep untrusted smart-tag values from executing shortcodes (isolated PHP) @fresh @security", async () => {
  test.setTimeout(120_000);
  await withWordPressCore((core) => runSecurityRegression("smart-tags", 46, core));
});
