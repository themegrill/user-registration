import { expect, test } from "@playwright/test";
import { ensureFirstRun, firstFormId, registerOn, registrationPageFor } from "../support/urm";
import { deleteUserByEmail, loginAsAdmin, newVisitor, restNonce } from "../support/wp";
import { uniqueEmail, uniqueUsername, STRONG_PASSWORD } from "../support/env";
import { wpCli, wpCliAvailable } from "../support/cli";

/**
 * @area    security-and-captcha
 * @tier    fresh
 * @guards  1544
 * @source  verify-fix 2026-09-16
 * @why     A per-form captcha type resolving to empty (never selected, or a
 *          legacy site whose keys predate the per-type "enable" flag) was
 *          treated everywhere as "no captcha configured" instead of falling
 *          back to the site-wide default type. That silently skipped
 *          submission-time verification entirely -- a security bypass, not
 *          just a missing widget: a form the admin believes is
 *          captcha-protected accepted submissions with zero verification.
 *          Uses a form duplicated from the site's default (the CPT has no
 *          REST route, so wp-cli is the only way to create one in isolation
 *          without disturbing the shared default form other specs depend
 *          on) -- skips entirely where wp-cli is unavailable (Playground).
 *          Does not cover login-form captcha, hCaptcha/v3/Cloudflare, or the
 *          admin dropdown UI itself.
 */
test.describe("captcha @fresh", () => {
  test("a form with captcha enabled but no captcha type selected still verifies the response @fresh @security-and-captcha", async ({
    page,
    browser,
  }) => {
    test.skip(!wpCliAvailable(), "wp-cli is not reachable in this environment");

    await loginAsAdmin(page);
    await ensureFirstRun(page);
    const defaultFormId = await firstFormId(page);

    // Site-wide v2 keys configured, but the per-type "enable" flag left
    // unset -- the state a legacy site is left in (issue #1544).
    wpCli(["option", "update", "user_registration_captcha_setting_recaptcha_version", "v2"]);
    wpCli(["option", "update", "user_registration_captcha_setting_recaptcha_site_key", "qa-fake-site-key"]);
    wpCli(["option", "update", "user_registration_captcha_setting_recaptcha_site_secret", "qa-fake-secret-key"]);
    try {
      wpCli(["option", "delete", "user_registration_captcha_setting_recaptcha_enable_v2"]);
    } catch {
      // Already absent -- fine, that is the state this test wants.
    }

    // Duplicate the default form so this test never touches the shared
    // fixture other specs depend on.
    const defaultContent = wpCli(["post", "get", String(defaultFormId), "--field=post_content"]);
    const formId = Number(
      wpCli([
        "post",
        "create",
        "--post_type=user_registration",
        "--post_title=QA Captcha Registration Form",
        "--post_status=publish",
        `--post_content=${defaultContent}`,
        "--porcelain",
      ]),
    );

    wpCli(["post", "meta", "update", String(formId), "user_registration_form_setting_enable_recaptcha_support", "1"]);
    // `wp post meta update` silently no-ops when the new value is empty and the
    // key does not exist yet, so it can never actually store an empty string --
    // exactly the state this test needs. `wp eval` bypasses that heuristic.
    wpCli([
      "eval",
      `update_post_meta( ${formId}, 'user_registration_form_setting_configured_captcha_type', '' );`,
    ]);

    try {
      const url = await registrationPageFor(page, formId);

      const guestCtx = await newVisitor(browser);
      const guestPage = await guestCtx.newPage();
      await guestPage.goto(url, { waitUntil: "domcontentloaded" });

      const captchaWidget = guestPage.locator(
        "#ur-google-recaptcha_0, .g-recaptcha, iframe[src*='recaptcha'], iframe[src*='hcaptcha'], iframe[src*='challenges.cloudflare']",
      );
      await expect(
        captchaWidget.first(),
        "captcha widget must render when the site has usable keys, even with no per-form type selected",
      ).toBeAttached();

      // registerOn() fills every field the form actually has (including a
      // membership selector, required here since the form was duplicated
      // from the site's default) and submits -- deliberately not solving the
      // captcha, since that is the regression itself.
      const account = await registerOn(guestPage, url, {
        username: uniqueUsername(),
        email: uniqueEmail(),
        password: STRONG_PASSWORD,
      });
      await guestPage.waitForTimeout(2000);

      await expect(
        guestPage.locator("body"),
        "an unsolved captcha-protected submission must be rejected, not silently accepted",
      ).toContainText(/captcha/i);

      const nonce = await restNonce(page);
      const userExists = await page.evaluate(
        async ({ username, nonce }) => {
          const res = await fetch(`/wp-json/wp/v2/users?search=${encodeURIComponent(username)}`, {
            headers: { "X-WP-Nonce": nonce },
            credentials: "same-origin",
          });
          const json = await res.json();
          return Array.isArray(json) && json.length > 0;
        },
        { username: account.username, nonce },
      );
      expect(userExists, "no account should have been created without a verified captcha").toBe(false);

      await guestCtx.close();
      await deleteUserByEmail(page, account.email);
    } finally {
      wpCli(["post", "delete", String(formId), "--force"]);
      wpCli(["option", "delete", "user_registration_captcha_setting_recaptcha_version"]);
      wpCli(["option", "delete", "user_registration_captcha_setting_recaptcha_site_key"]);
      wpCli(["option", "delete", "user_registration_captcha_setting_recaptcha_site_secret"]);
    }
  });
});
