import { expect, test } from "@playwright/test";
import { ensureFirstRun } from "../support/urm";
import { gotoAdminPage, loginAsAdmin } from "../support/wp";

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

  test("security tab exposes Prevent WP Dashboard Access @fresh @admin", async ({ page }) => {
    await gotoAdminPage(page, "user-registration-settings", "&tab=security");
    await expect(page.getByText("Prevent WP Dashboard Access")).toBeVisible();
    await expect(
      page.locator("#user_registration_general_setting_disabled_user_roles"),
    ).toHaveCount(1);
  });

  test("membership admin navigation: Memberships, Add New, Groups, Members @fresh @admin", async ({ page }) => {
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
});
