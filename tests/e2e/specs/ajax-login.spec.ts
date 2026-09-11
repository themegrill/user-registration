import { expect, test, type Page } from "@playwright/test";
import { ensureFirstRun, firstFormId, registerOn, registrationPageFor } from "../support/urm";
import { deleteUserByEmail, loginAsAdmin, newVisitor } from "../support/wp";

/**
 * Ported from UR-Automation `05__setting_login_options` — "Form Login and edit
 * profile is working properly in Ajax" (tag ci_cd).
 *
 * The setting is `ur_login_ajax_submission`, and it is not on any settings tab:
 * it belongs to the Login Forms builder (`page=user-registration-login-forms`),
 * which is also where Login Methods lives. Both are rendered as hidden
 * checkboxes behind styled toggles, so they are set on the input and saved with
 * the builder's own "Update Form" button.
 */
/**
 * Set the toggle and save, returning what it was BEFORE the change.
 *
 * Returning the previous value is what lets the caller skip a separate
 * `ajaxLoginEnabled()` read just to know what to restore. The builder is a
 * heavy screen and this spec was loading it four times; under Playground's WASM
 * PHP that was the difference between comfortably inside CI's per-test ceiling
 * and timing out on it.
 */
async function setAjaxLogin(page: Page, on: boolean): Promise<boolean> {
  await page.goto("/wp-admin/admin.php?page=user-registration-login-forms");
  await page.locator("#ur_login_ajax_submission").waitFor({ state: "attached", timeout: 30_000 });
  const was = await page.locator("#ur_login_ajax_submission").isChecked();
  await page.locator("#ur_login_ajax_submission").evaluate((el, on) => {
    const box = el as HTMLInputElement;
    if (box.checked === on) return;
    box.checked = on;
    box.dispatchEvent(new Event("change", { bubbles: true }));
    const jq = (window as any).jQuery;
    if (jq) jq(box).trigger("change");
  }, on);
  // Wait for the save to actually answer, not for a fixed 3s. This function is
  // called twice per run of this spec, so the sleep was six seconds of dead
  // time — and on a slow runner three seconds was ALSO not enough: navigating
  // away mid-flight aborts the request and the setting silently does not
  // persist. Waiting on the response is both faster and correct.
  const saved = page.waitForResponse(
    (r) =>
      r.url().includes("admin-ajax.php") &&
      r.request().method() === "POST" &&
      (r.request().postData() ?? "").includes("user_registration_login_settings_save_action"),
    { timeout: 30_000 },
  );
  await page.locator("[name=save_login_form]").first().click();
  await saved;

  return was;
}

async function ajaxLoginEnabled(page: Page): Promise<boolean> {
  await page.goto("/wp-admin/admin.php?page=user-registration-login-forms");
  const box = page.locator("#ur_login_ajax_submission");
  await box.waitFor({ state: "attached", timeout: 30_000 });
  return await box.isChecked();
}

test.describe("ajax login @fresh", () => {
  test("login submits over XHR when Ajax Login is enabled @fresh @login-forms", async ({
    page,
    browser,
  }) => {
    await loginAsAdmin(page);
    await ensureFirstRun(page);

    // `original` comes back from the write itself rather than from a read
    // beforehand, so the builder screen is loaded once here instead of twice.
    let original: boolean | null = null;
    try {
      original = await setAjaxLogin(page, true);
      expect(await ajaxLoginEnabled(page), "Ajax Login did not persist").toBe(true);

      const url = await registrationPageFor(page, await firstFormId(page));
      const visitor = await newVisitor(browser);
      const user = await visitor.newPage();
      const account = await registerOn(user, url);

      // Watch for the plugin's own login handler rather than for "no
      // navigation": the page may still redirect after a successful XHR, so
      // absence of navigation is not what distinguishes ajax from post.
      // The handler name travels in the query string, not the body: the call
      // is POST admin-ajax.php?action=user_registration_ajax_login_submit with
      // only the credentials in the body. Matching on postData finds nothing.
      const loginCall = user.waitForRequest(
        (r) =>
          r.method() === "POST" &&
          r.url().includes("admin-ajax.php") &&
          r.url().includes("action=user_registration_ajax_login_submit"),
        { timeout: 30_000 },
      );

      await user.goto("/my-account/");
      await user.fill("input[name=username]", account.username);
      await user.fill("input[name=password]", account.password);
      await user.locator("button:has-text('Login'), input[type=submit][name=login]").first().click();

      const request = await loginCall;
      expect(request, "no ajax login request was sent").toBeTruthy();
      await expect(user.locator(".user-registration-MyAccount-navigation")).toBeVisible();

      await deleteUserByEmail(page, account.email);
      await visitor.close();
    } finally {
      // A global setting: restore whatever the site had, pass or fail. `null`
      // means the write never got as far as reading the old value, so there is
      // nothing trustworthy to restore — guessing `false` here would turn the
      // setting OFF on a site that had it on.
      if (original !== null) await setAjaxLogin(page, original);
    }
  });
});
