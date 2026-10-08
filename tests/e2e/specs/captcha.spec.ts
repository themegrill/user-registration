import { expect, test, type Page } from "@playwright/test";
import {
  ensureFirstRun,
  firstFormId,
  openRegistrationForm,
  registerOn,
  registrationPageFor,
  submitRegistration,
} from "../support/urm";
import { deleteUserByEmail, gotoAdminPage, loginAsAdmin, newVisitor, restNonce } from "../support/wp";
import { uniqueEmail, uniqueUsername, STRONG_PASSWORD } from "../support/env";
import { wpCli, wpCliAvailable } from "../support/cli";

/**
 * Cloudflare's published testing keys. They need no Cloudflare account and
 * behave identically on every host, which is what makes a captcha spec
 * deterministic at all: the "spent" secret rejects every token it is given with
 * the `timeout-or-duplicate` error code, which is precisely the state a real
 * visitor reaches by submitting twice.
 *
 * https://developers.cloudflare.com/turnstile/troubleshooting/testing/
 */
const TURNSTILE_SITE_KEY = "1x00000000000000000000AA";
const TURNSTILE_SPENT_SECRET = "3x0000000000000000000000000000000AA";

/**
 * Point the site's captcha at Cloudflare Turnstile with the given secret.
 *
 * Saving the Turnstile card is the whole fixture: `save_captcha_settings()`
 * writes the posted keys *and* sets `..._recaptcha_version` to the card's
 * provider, so one save both configures Turnstile and makes it the active one.
 */
async function configureTurnstile(page: Page, secret: string) {
  await gotoAdminPage(page, "user-registration-settings", "&tab=registration_login&section=captcha");

  const card = page.locator("#cloudflare");
  await expect(card).toHaveCount(1);

  // The provider cards are accordions and their fields are display:none until
  // the header is clicked, so a fill() on a collapsed card times out.
  await card.locator(".ur-captcha-settings-header").click();
  const siteKey = card.locator("#user_registration_captcha_setting_recaptcha_site_key_cloudflare");
  await expect(siteKey).toBeVisible();

  await siteKey.fill(TURNSTILE_SITE_KEY);
  await card.locator("#user_registration_captcha_setting_recaptcha_site_secret_cloudflare").fill(secret);
  await card.locator("button.captcha-save-btn").click();

  // Assert the saved state rather than the save notice: the settings screen
  // carries unrelated admin notices whose text a "did it save" locator matches
  // just as readily, and the reloaded field value is the thing we actually need
  // to be true before the rest of the spec means anything.
  await gotoAdminPage(page, "user-registration-settings", "&tab=registration_login&section=captcha");
  await expect(page.locator("#user_registration_captcha_setting_recaptcha_site_key_cloudflare")).toHaveValue(
    TURNSTILE_SITE_KEY,
    { timeout: 15_000 },
  );
}

/**
 * Turn Turnstile on for one form.
 *
 * Both meta values matter and for different reasons. `enable_recaptcha_support`
 * is what makes `ur_get_recaptcha_node()` emit a captcha node at all;
 * `configured_captcha_type` is what the per-shortcode `user_registration_params`
 * filter reports to the front-end script, and it defaults to `v2` rather than to
 * the global provider — leave it empty and the front end reads
 * `g-recaptcha-response` on a Turnstile form, finds nothing, and refuses to
 * submit before any request is made.
 *
 * The controls live behind the builder's Settings tab. Setting them directly
 * and firing the change event jQuery listens for is the same idiom the security
 * spec uses for its select2 control, and it avoids driving the builder's tab
 * chrome, which is not what this spec is about.
 */
async function setFormCaptcha(page: Page, formId: number, enabled: boolean) {
  await gotoAdminPage(page, "add-new-registration", `&edit-registration=${formId}&tab=field-options`);

  const toggle = page.locator("#user_registration_form_setting_enable_recaptcha_support");
  await expect(toggle).toHaveCount(1);

  await toggle.evaluate((el, enabled) => {
    const box = el as HTMLInputElement;
    box.checked = enabled as boolean;
    box.dispatchEvent(new Event("change", { bubbles: true }));
    const jq = (window as any).jQuery;
    if (jq) jq(box).trigger("change");
  }, enabled);

  await page.locator("#user_registration_form_setting_configured_captcha_type").evaluate((el, enabled) => {
    const sel = el as HTMLSelectElement;
    sel.value = enabled ? "cloudflare" : "";
    sel.dispatchEvent(new Event("change", { bubbles: true }));
    const jq = (window as any).jQuery;
    if (jq) jq(sel).trigger("change");
  }, enabled);

  // The builder saves over XHR and prints no notice a spec can rely on, so wait
  // on the request itself and then on the reloaded state.
  await Promise.all([
    page.waitForResponse(
      (r) => r.url().includes("admin-ajax.php") && r.request().postData()?.includes("form_save_action") === true,
      { timeout: 30_000 },
    ),
    page.locator("#save_form_footer").click(),
  ]);

  await gotoAdminPage(page, "add-new-registration", `&edit-registration=${formId}&tab=field-options`);
  await expect(page.locator("#user_registration_form_setting_enable_recaptcha_support")).toBeChecked({
    checked: enabled,
    timeout: 15_000,
  });
}

/**
 * Put a token in the form the way a solved widget would, and submit.
 *
 * The token's value is irrelevant — the "spent" secret rejects whatever it is
 * given — so seeding it rather than waiting on Cloudflare's widget keeps the
 * spec off a third-party script's timing without weakening anything it asserts.
 * What matters is only that the field is non-empty, because an empty one is a
 * different branch of the product with a different message.
 */
async function submitWithToken(page: Page, url: string) {
  const { form } = await openRegistrationForm(page, url);

  // Captcha has to actually be on this form, or the rest of the spec would pass
  // for the wrong reason.
  await expect(form.locator("#ur-recaptcha-node")).toHaveCount(1);

  await form.evaluate((el) => {
    const node = el.querySelector("#ur-recaptcha-node") as HTMLElement;
    let input = el.querySelector('[name="cf-turnstile-response"]') as HTMLInputElement | null;
    if (!input) {
      input = document.createElement("input");
      input.type = "hidden";
      input.name = "cf-turnstile-response";
      node.appendChild(input);
    }
    input.value = "qa-seeded-turnstile-token";
  });

  await submitRegistration(page);
}

test.describe("captcha @fresh", () => {
  test.afterAll(async ({ browser }) => {
    // Captcha is global state plus a form setting: leaving either behind turns
    // every other registration spec into a captcha spec.
    const context = await browser.newContext();
    const page = await context.newPage();
    await loginAsAdmin(page).catch(() => {});
    await setFormCaptcha(page, await firstFormId(page), false).catch(() => {});
    await context.close();
  });

  /**
   * @area    security-and-captcha
   * @tier    fresh
   * @guards  #1409
   * @source  verify-fix 2026-09-14
   * @why     A Turnstile token is single use and the server verifies it before
   *          it validates any field, so a submission rejected for a typo has
   *          already spent it. #1409 was the visitor then being told to contact
   *          the administrator about a token only the visitor can replace, on
   *          every retry, until they reloaded the page.
   *
   *          Asserts the message a spent token produces. It deliberately does
   *          NOT assert the client-side per-form widget refresh that is the
   *          other half of that fix: reproducing that needs two registration
   *          forms on one page, and the free plugin refuses to create a second
   *          one (`UR_AJAX::create_form()` gates it behind pro's
   *          multiple-registration module), so it cannot be staged on a fresh
   *          free install. Nor does it assert real widget rendering — the token
   *          is seeded, so a Cloudflare outage cannot turn this red.
   *
   *          Runs around 50s rather than the usual 30s ceiling, and nearly all
   *          of that is fixture: captcha is global state plus a per-form
   *          setting, so there are two admin saves to make and each has to be
   *          read back before the front end means anything. The submit it
   *          actually measures is one request.
   */
  test("a spent Turnstile token asks the visitor to solve it again @fresh @security-and-captcha", async ({
    page,
    browser,
  }) => {
    await loginAsAdmin(page);
    await ensureFirstRun(page);
    const formId = await firstFormId(page);

    await configureTurnstile(page, TURNSTILE_SPENT_SECRET);
    await setFormCaptcha(page, formId, true);
    const url = await registrationPageFor(page, formId);

    const visitor = await newVisitor(browser);
    const guest = await visitor.newPage();
    await submitWithToken(guest, url);

    const notice = guest.locator(".user-registration-error, .ur-error, .ur-message").first();
    await expect(notice).toBeVisible({ timeout: 20_000 });
    const message = await notice.innerText();

    // The point of the fix: a spent token is the visitor's to replace, so the
    // message has to tell them to, not send them to the administrator.
    expect(message, `submitting a spent token said: ${message}`).toMatch(/captcha has expired/i);
    expect(message, "a spent token still blamed the site administrator").not.toMatch(
      /contact your site administrator/i,
    );

    await visitor.close();
  });

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
