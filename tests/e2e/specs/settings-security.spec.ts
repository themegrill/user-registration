import { expect, test } from "@playwright/test";
import { ensureFirstRun, firstFormId, loginToMyAccount, registerOn, registrationPageFor } from "../support/urm";
import { deleteUserByEmail, gotoAdminPage, loginAsAdmin, newVisitor } from "../support/wp";

/**
 * Ported from UR-Automation `04__setting_general_options/01__DashboardAccess`
 * — "Prevent WP Dashboard Access" enabled (tag ci_cd) and not enabled
 * (core_feature). The setting lives on the Security tab despite the suite's
 * directory name.
 *
 * The control is a select2-backed multi-select. Driving select2's rendered
 * markup is what makes these tests brittle in the original; setting the
 * underlying <select> and firing the change event jQuery is listening for does
 * the same job without depending on select2's DOM.
 */
async function setDisabledRoles(page: import("@playwright/test").Page, roles: string[]) {
  await gotoAdminPage(page, "user-registration-settings", "&tab=security");
  const select = page.locator("#user_registration_general_setting_disabled_user_roles");
  await expect(select).toHaveCount(1);

  await select.evaluate((el, roles) => {
    const sel = el as HTMLSelectElement;
    for (const opt of Array.from(sel.options)) opt.selected = roles.includes(opt.value);
    sel.dispatchEvent(new Event("change", { bubbles: true }));
    const jq = (window as any).jQuery;
    if (jq) jq(sel).trigger("change");
  }, roles);

  await page.locator("input[name=save]").first().click();
  await page.waitForLoadState("domcontentloaded");
  // The plugin prints its own inline notice (class "inline updated"), not the
  // WordPress #message box a settings screen usually uses.
  await expect(page.locator(".updated, .notice-success, #message").first()).toContainText(
    /settings have been saved/i,
  );
}

/** What the select currently holds, read back from the saved page. */
async function savedDisabledRoles(page: import("@playwright/test").Page): Promise<string[]> {
  await gotoAdminPage(page, "user-registration-settings", "&tab=security");
  return await page
    .locator("#user_registration_general_setting_disabled_user_roles")
    .evaluate((el) =>
      Array.from((el as HTMLSelectElement).selectedOptions).map((o) => o.value),
    );
}

test.describe("prevent wp dashboard access @fresh", () => {
  test.afterEach(async ({ page }) => {
    // Global setting: leave the site as it was found, or the next spec in the
    // file inherits a half-configured site.
    await loginAsAdmin(page).catch(() => {});
    await setDisabledRoles(page, []).catch(() => {});
  });

  test("the setting round-trips through a save @fresh @security", async ({ page }) => {
    await loginAsAdmin(page);

    await setDisabledRoles(page, ["subscriber"]);
    expect(await savedDisabledRoles(page)).toContain("subscriber");

    await setDisabledRoles(page, []);
    expect(await savedDisabledRoles(page)).not.toContain("subscriber");
  });

  test("a subscriber keeps the admin bar when the setting is off @fresh @security", async ({ page, browser }) => {
    await loginAsAdmin(page);
    await ensureFirstRun(page);
    await setDisabledRoles(page, []);
    const url = await registrationPageFor(page, await firstFormId(page));

    const visitor = await newVisitor(browser);
    const user = await visitor.newPage();
    const account = await registerOn(user, url);
    await loginToMyAccount(user, account.username, account.password);

    // The Robot original asserts on the admin bar rather than on reaching
    // wp-admin, because a subscriber has no dashboard to speak of either way.
    await expect(user.locator("#wpadminbar")).toBeVisible();
    await deleteUserByEmail(page, account.email);
    await visitor.close();
  });
});
