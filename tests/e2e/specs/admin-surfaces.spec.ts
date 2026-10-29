import { expect, test } from "@playwright/test";
import { ABSENT, getOptions, restoreOptions, seedAvailable } from "../support/seed";
import { discardRegistrationForms, ensureFirstRun, ensureMembershipEnabled } from "../support/urm";
import { gotoAdminPage, loginAsAdmin, restNonce } from "../support/wp";

const WIZARD_API = "/index.php?rest_route=/user-registration/v1/getting-started";

/**
 * Ported from UR-Automation `09_ur_membership` — "Verify Admin Membership Basic
 * Navigation" (tag ci_cd) — widened to the surrounding admin surfaces, because
 * every other gate test starts by reaching one of these screens. If this file
 * fails, nothing else in the suite is meaningful.
 */
test.describe("admin surfaces @fresh", () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test("settings screen renders every tab @fresh @admin", async ({ page }) => {
    await gotoAdminPage(page, "user-registration-settings");
    // Read from the plugin's own tab nav rather than the page body, so a theme
    // string elsewhere on the screen cannot satisfy the assertion.
    for (const tab of [
      "General",
      "Registration & Login",
      "My Account",
      "Emails",
      "Payment",
      "Membership",
      "Integration",
      "Security",
      "Advanced",
    ]) {
      await expect(
        page.locator(".ur-tab-content, #mainform, .wrap").getByText(tab, { exact: true }).first(),
      ).toBeVisible();
    }
  });

  /**
   * @area    admin
   * @tier    fresh
   * @guards  #1718
   * @source  write-spec 2026-10-05
   * @why     Invite Codes showed the Popups bullets and Popups showed only the generic
   *          upgrade notice. Guards each locked section rendering its own teaser; does
   *          not assert exact bullet wording, which product copy may change.
   */
  test("locked Registration & Login sections each show their own upgrade teaser @fresh @admin", async ({ page }) => {
    const teaser = page.locator(".user-registration-upsell");

    await gotoAdminPage(page, "user-registration-settings", "&tab=registration_login&section=popup");
    await expect(teaser).toContainText(/popup/i);
    await expect(teaser.locator("li")).not.toHaveCount(0);
    await expect(page.getByText("To unlock this setting, consider upgrading")).toHaveCount(0);

    await gotoAdminPage(page, "user-registration-settings", "&tab=registration_login&section=invite-code");
    await expect(teaser).toContainText(/invite/i);
    await expect(teaser.locator("li")).not.toHaveCount(0);
    await expect(teaser).not.toContainText(/popup/i);
  });

  test("security tab exposes Prevent WP Dashboard Access @fresh @admin", async ({ page }) => {
    await gotoAdminPage(page, "user-registration-settings", "&tab=security");
    await expect(page.getByText("Prevent WP Dashboard Access")).toBeVisible();
    await expect(
      page.locator("#user_registration_general_setting_disabled_user_roles"),
    ).toHaveCount(1);
  });

  test("membership admin navigation: Memberships, Add New, Groups, Members @fresh @admin", async ({ page }) => {
    await ensureMembershipEnabled(page);
    await gotoAdminPage(page, "user-registration-membership");
    await expect(page.locator("#wpbody-content")).toBeVisible();
    // The membership module registers its own submenus; assert on the menu the
    // plugin drew, not on a page heading, because several of these screens are
    // conditionally registered and a missing one is the actual regression.
    const menu = page.locator("#adminmenu");
    await expect(menu.getByRole("link", { name: /Memberships?/i }).first()).toBeVisible();
    await expect(menu.getByRole("link", { name: /Members/i }).first()).toBeVisible();
  });

  test("registration forms list shows at least one form @fresh @admin", async ({ page }) => {
    await ensureFirstRun(page);
    await page.goto("/wp-admin/edit.php?post_type=user_registration");
    await expect(page.locator("#the-list tr[id^='post-']").first()).toBeVisible();
  });

  test("payment settings masks secret keys with show/hide toggle @fresh @admin", async ({ page }) => {
    await gotoAdminPage(page, "user-registration-settings", "&tab=payment");
    const paypal = page.locator("#paypal");
    await paypal.locator(".user-registration-card__header").click();

    // A clean install has PayPal off, and its keys only render once it is on. Turning it
    // on defaults the mode to Production, which hides the test keys, so pick Test mode
    // rather than relying on whatever the site happened to have saved.
    if (!(await paypal.locator("#user_registration_paypal_enabled").isChecked())) {
      await paypal.locator("label[for='user_registration_paypal_enabled'], .user-registration-toggle-slider").first().click();
    }
    await paypal.locator("#user_registration_global_paypal_mode").selectOption("test");

    const secretInput = paypal.locator("#user_registration_global_paypal_test_client_secret");
    await expect(secretInput).toHaveAttribute("type", "password");

    const toggleBtn = paypal.locator(".user-registration-password-input-wrapper").filter({ has: page.locator("#user_registration_global_paypal_test_client_secret") }).locator(".ur-toggle-password");
    await expect(toggleBtn).toBeVisible();
    await toggleBtn.click();
    await expect(secretInput).toHaveAttribute("type", "text");
    await toggleBtn.click();
    await expect(secretInput).toHaveAttribute("type", "password");
  });

  test("setup wizard payment step reveals secret keys with a show/hide toggle @fresh @admin", async ({ page }) => {
    // The wizard is a React bundle; a checkout that was never built serves an HTML page for it.
    const bundle = await page.request.get("/wp-content/plugins/user-registration/chunks/welcome.js");
    test.skip(!(bundle.headers()["content-type"] ?? "").includes("javascript"), "chunks/welcome.js is not built on this site; run pnpm build first");

    // The wizard's Next button SAVES the membership answer, and this test never put it back.
    // Remember the saved answer and restore it, the way setup-wizard.spec.ts does. This puts
    // the setting back; the form it altered is discarded below, because the setting
    // alone does not undo that.
    const headers = { "X-WP-Nonce": await restNonce(page) };
    const before = await page.request.get(`${WIZARD_API}/welcome`, { headers });
    expect(before.ok()).toBeTruthy();
    const original = (await before.json()).data;
    // On a fresh site nothing has answered yet, so there is nothing for the REST
    // call above to put back, and the wizard's "Yes" would stay stored for every
    // later spec. The seed endpoint can put the option back exactly, including
    // back to "not set".
    const answerKey = "urm_onboarding_membership_type";
    const savedAnswer = seedAvailable() ? await getOptions(answerKey) : null;

    try {
      await gotoAdminPage(page, "user-registration-welcome", "&tab=setup-wizard");
      await page.getByRole("button", { name: "Next" }).click();
      await expect(page.getByRole("heading", { name: "Create Membership" })).toBeVisible();
      // The Payment tab ignores clicks until the Membership step has finished mounting.
      await expect(async () => {
        await page.getByRole("button", { name: "Go to Payment" }).click();
        await expect(page.getByRole("heading", { name: "Payments" })).toBeVisible({ timeout: 2_000 });
      }).toPass();

      const paypalSwitch = page.getByText(/^pays?pal$/i).locator("xpath=following::label[contains(@class,'chakra-switch')][1]");
      await expect(paypalSwitch).toBeVisible();
      // A clean install has the gateways off, so the secret fields only render once PayPal is on.
      if (!(await paypalSwitch.locator("input").isChecked())) await paypalSwitch.click();

      // Found by EITHER label. A locator is re-evaluated on every use, and the toggle's name
      // flips from "Show secret" to "Hide secret" once clicked, so a group found by "Show secret"
      // alone stops existing the moment the secret is revealed and the input goes with it.
      const secretGroup = page
        .locator(".chakra-input__group")
        .filter({ has: page.getByRole("button", { name: /^(show|hide) secret$/i }) })
        .first();
      const secretInput = secretGroup.locator("input");
      const showSecret = secretGroup.getByRole("button", { name: "Show secret" });
      await expect(secretInput).toHaveAttribute("type", "password");

      await showSecret.click();
      await expect(secretInput).toHaveAttribute("type", "text");
      await secretGroup.getByRole("button", { name: "Hide secret" }).click();
      await expect(secretInput).toHaveAttribute("type", "password");

      // A secret revealed in one mode must not stay revealed after switching to the other mode.
      await showSecret.click();
      await expect(secretInput).toHaveAttribute("type", "text");
      await page.locator("select").filter({ has: page.locator("option", { hasText: "Production" }) }).first().selectOption("production");
      await expect(page.locator(".chakra-input__group input").first()).toHaveAttribute("type", "password");
    } finally {
      if (original?.membership_type) {
        await page.request.post(`${WIZARD_API}/welcome`, {
          headers,
          data: {
            membership_type: original.membership_type,
            allow_usage_tracking: original.allow_usage_tracking,
            admin_email: original.admin_email,
          },
        });
      }
      // Next also turns the default registration form into one with a required Membership
      // field, and putting the answer back leaves it that way. Every later spec that
      // registers a user then meets a submit button that cannot be pressed, or a
      // registration that fails. The product only ever adds the field, so the form is
      // thrown away and the three options that remember it are cleared, which makes the
      // next first-run bootstrap provision a plain one.
      //
      // Only on a disposable site: this deletes forms, and a developer's own site (no seed
      // endpoint) has real ones. There the answer is restored over REST above, as before.
      if (savedAnswer) {
        await restoreOptions(savedAnswer);
        await discardRegistrationForms(page);
        await restoreOptions({
          user_registration_default_form_page_id: ABSENT,
          user_registration_registration_form: ABSENT,
          urm_membership_default_form_page_id: ABSENT,
        });
      }
    }
  });
});
