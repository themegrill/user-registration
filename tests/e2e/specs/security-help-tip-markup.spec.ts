import { test } from "@playwright/test";
import { runSecurityRegression, withWordPressCore } from "../support/security";

// This spec executes the isolated PHP regression; it does not mutate a live site.
test("Keep markup out of help tips that Tooltipster renders as HTML (isolated PHP) @fresh @security", async () => {
  test.setTimeout(120_000);
  await withWordPressCore((core) => runSecurityRegression("help-tip-markup", 61, core));
});
