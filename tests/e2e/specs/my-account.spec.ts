import { expect, test } from "@playwright/test";
import { STRONG_PASSWORD } from "../support/env";
import { ensureFirstRun, firstFormId, loginToMyAccount, registerOn, registrationPageFor } from "../support/urm";
import { deleteUserByEmail, loginAsAdmin, newVisitor } from "../support/wp";

/**
 * Ported from UR-Automation `03__my_account` — "Validate working of Edit
 * profile" (tag ci_cd) and the two change-password cases (core_feature).
 *
 * The Robot original asserted on the "User profile updated successfully."
 * notice and then re-read the inputs. Keeping that second half matters more
 * than the first: UR-4822, UR-4791 and UR-4798 were all bugs where the notice
 * appeared and the data did not survive, so a spec that stops at the notice
 * would have passed through every one of them.
 */
/**
 * Its own describe, deliberately: the runner filters tiers with `--grep`, and
 * grep sees the FULL title, describe included. Left inside `my account @fresh`
 * the tag below would be overridden by its parent and the test would keep
 * running in the fresh tier — the retag has to happen at both levels or at
 * neither.
 */
test.describe("my account profile fields @demo", () => {
  /**
   * `@demo`, not `@fresh`, and the reason is the Country field below.
   *
   * The registration form a clean install ends up with has four fields —
   * username, email, password, confirm password — so its Edit Profile form
   * offers exactly `user_registration_user_login` (readonly) and
   * `user_registration_user_email`. There is no third field to mutate, and
   * email is the one field this test must not touch, because changing it
   * diverts into the pending-email-change flow.
   *
   * This passed for as long as it did because `test-urm.local` has a Country
   * field on its form: the test was reading site configuration as if it were
   * product behaviour. Adding the field here instead would mean hand-writing
   * the builder's own field JSON in the suite — the third copy of a product
   * detail that already lives in two places in the plugin — so the honest fix
   * is the tier tag. A site with demo content has the field; CI, which runs
   * only `@fresh`, no longer runs a test that cannot pass there.
   */
  test("edit profile saves, and the change survives a reload @demo @my-account", async ({ page, browser }) => {
    await loginAsAdmin(page);
    await ensureFirstRun(page);
    const url = await registrationPageFor(page, await firstFormId(page));

    const visitor = await newVisitor(browser);
    const user = await visitor.newPage();
    const account = await registerOn(user, url);

    await loginToMyAccount(user, account.username, account.password);
    await expect(user.locator(".user-registration-MyAccount-navigation")).toBeVisible();

    // The landing view is read-only Profile Details; the editable form is
    // behind the Edit Profile action.
    await user.goto("/my-account/edit-profile/?action=edit");
    const form = user.locator("form.edit-profile, form").filter({
      has: user.locator("input[name=save_account_details]"),
    });
    await expect(form).toBeVisible();

    // Country is the safe field to mutate: changing the email would divert
    // into the pending-email-change flow, which is a different test.
    const country = form.locator("select[name^='user_registration_country_']");
    await expect(country).toHaveCount(1);
    const chosen = "NP";
    await country.selectOption(chosen);

    await form.locator("input[name=save_account_details]").click();
    await user.waitForLoadState("domcontentloaded");

    await expect(
      user.locator(".user-registration-message, .ur-message").first(),
    ).toContainText(/updated|success/i);

    // The assertion that actually matters.
    await user.goto("/my-account/edit-profile/?action=edit");
    await expect(
      user.locator("select[name^='user_registration_country_']"),
    ).toHaveValue(chosen);

    // And the fields nobody touched are still what they were.
    await expect(
      user.locator("input[name^='user_registration_user_email']"),
    ).toHaveValue(account.email);

    await deleteUserByEmail(page, account.email);
    await visitor.close();
  });
});

test.describe("my account @fresh", () => {
  test("change password rejects reusing the current password @fresh @my-account", async ({ page, browser }) => {
    await loginAsAdmin(page);
    await ensureFirstRun(page);
    const url = await registrationPageFor(page, await firstFormId(page));

    const visitor = await newVisitor(browser);
    const user = await visitor.newPage();
    const account = await registerOn(user, url);
    await loginToMyAccount(user, account.username, account.password);

    // The endpoint slug is an option (`..._change_password_endpoint`, default
    // "edit-password"), so follow the link the product renders rather than
    // hardcoding a path that a site can legitimately rename.
    await gotoChangePassword(user);
    await user.fill("#password_current", account.password);
    await user.fill("#password_1", account.password);
    await user.fill("#password_2", account.password);
    await user.locator("[name=save_change_password]").click();
    await user.waitForLoadState("domcontentloaded");

    await expect(user.locator("body")).toContainText(
      /must not be same as old password/i,
    );
    await deleteUserByEmail(page, account.email);
    await visitor.close();
  });

  test("change password accepts a new password and it works on the next login @fresh @my-account", async ({ page, browser }) => {
    await loginAsAdmin(page);
    await ensureFirstRun(page);
    const url = await registrationPageFor(page, await firstFormId(page));

    const visitor = await newVisitor(browser);
    const user = await visitor.newPage();
    const account = await registerOn(user, url);
    await loginToMyAccount(user, account.username, account.password);

    const next = `${STRONG_PASSWORD}-next`;
    // The endpoint slug is an option (`..._change_password_endpoint`, default
    // "edit-password"), so follow the link the product renders rather than
    // hardcoding a path that a site can legitimately rename.
    await gotoChangePassword(user);
    await user.fill("#password_current", account.password);
    await user.fill("#password_1", next);
    await user.fill("#password_2", next);
    await user.locator("[name=save_change_password]").click();
    await user.waitForLoadState("domcontentloaded");

    // The Robot original stopped at the form reappearing. Proving the new
    // password actually authenticates is the point of changing it.
    const second = await visitor.newPage();
    await loginToMyAccount(second, account.username, next);
    await expect(second.locator(".user-registration-MyAccount-navigation")).toBeVisible();

    await deleteUserByEmail(page, account.email);
    await visitor.close();
  });
});

/** Reach the Change Password form the way a user does. */
async function gotoChangePassword(user: import("@playwright/test").Page) {
  // The Change Password action lives on the read-only Profile Details view;
  // the ?action=edit view replaces the tab strip with a back button.
  // `domcontentloaded`, not the default `load`: waiting for every subresource
  // here produced `net::ERR_ABORTED` on both CI attempts of both change-password
  // specs. The plugin redirects within the account area while the page is still
  // pulling assets, and that supersedes the navigation Playwright is waiting on.
  // The locator wait below is the real readiness signal either way.
  await user.goto("/my-account/edit-profile/", { waitUntil: "domcontentloaded" });
  await user.locator("a:has-text('Change Password')").first().click();
  await user.waitForLoadState("domcontentloaded");
  await user.locator("#password_current").waitFor({ state: "visible", timeout: 20_000 });
}
