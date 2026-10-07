import { expect, test } from "@playwright/test";
import { ensureFirstRun, firstFormId } from "../support/urm";
import { loginAsAdmin } from "../support/wp";

/**
 * Regression coverage for one of the five console defects fixed under
 * themegrill/user-registration-pro#1684.
 *
 * The issue also reported the `username_length` advanced-setting field on the
 * Username field rendering an unparseable default value. That fix is real
 * (`includes/form/settings/class-ur-setting-user_login.php`) but is not
 * covered here: `UR_Form_Handler::create()`'s blank-form template already
 * bakes an explicit `"username_length":""` into every new form's saved JSON,
 * so a freshly-created form never exercises the PHP `default` fallback the
 * fix touches — only a pre-existing form saved before that key existed does,
 * and there is no fixture-safe way to manufacture that state without writing
 * directly to a form's `post_content` in the product's internal field-JSON
 * format, which is more coupling to an implementation detail than this spec
 * should take on.
 */
test.describe("form builder @fresh", () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await ensureFirstRun(page);
  });

  /**
   * @area    registration
   * @tier    fresh
   * @guards  themegrill/user-registration-pro#1684
   * @source  verify-fix 2026-10-01
   * @why     `ur-form-preview-template.php` called `wp_head()` before the
   *          `<!DOCTYPE html>` declaration. `wp_head()` echoes real output
   *          (title tag, meta tags, RSS links) immediately, so those bytes
   *          reached the browser ahead of the doctype — enough to force the
   *          page into Quirks Mode, which breaks standard CSS box-sizing on
   *          the preview relative to the live form. Verified failing
   *          (`document.compatMode === "BackCompat"`) against the pre-fix
   *          template before confirming it passes against the fix.
   */
  test("form preview page is not forced into Quirks Mode @fresh @registration", async ({ page }) => {
    const formId = await firstFormId(page);
    await page.goto(`/?ur_preview=true&form_id=${formId}`);

    const compatMode = await page.evaluate(() => document.compatMode);
    expect(compatMode, "the form preview page rendered in Quirks Mode").toBe("CSS1Compat");
  });
});
