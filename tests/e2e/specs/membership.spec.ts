import { expect, test } from "@playwright/test";
import { gotoAdminPage, loginAsAdmin } from "../support/wp";

/**
 * Ported from UR-Automation `09_ur_membership` — "Verify the creation of a new
 * membership plan >> Free" (tag ci_cd).
 *
 * Only the free plan is ported. Paid and Subscription are the other two ci_cd
 * cases, and both need gateway credentials configured on the site under test —
 * without them the form saves a plan nobody can buy, which asserts nothing the
 * free case does not already cover. They stay unported deliberately.
 *
 * The plan is deleted afterwards. Membership plans render as radio options on
 * every registration form, so a plan left behind changes the fixture that the
 * registration and my-account specs depend on.
 */
const planName = () => `QA Free ${Date.now().toString(36)}`;

async function deletePlan(page: import("@playwright/test").Page, name: string) {
  await gotoAdminPage(page, "user-registration-membership");
  const row = page.locator("tr", { hasText: name });
  if (!(await row.count())) return;
  // The list table exposes Trash/Delete as a row action revealed on hover.
  const action = row.first().locator("a[aria-label='Delete this item'], a:has-text('Trash')");
  if (!(await action.count())) return;
  // The row action is a hover-revealed link that Playwright reports as outside
  // the viewport, and `force` does not skip that positional check. This is
  // teardown, not an assertion about clickability, so dispatch it directly.
  await action.first().evaluate((el) => (el as HTMLElement).click());

  // Confirmation is a SweetAlert2 modal, not a native dialog, so a
  // `page.on("dialog")` handler never fires for it.
  const confirm = page.locator(".swal2-confirm");
  await confirm.waitFor({ state: "visible", timeout: 15_000 });
  await confirm.click();

  // The row leaving the DOM is NOT evidence of deletion: the list removes it
  // even when the server answers {"success":false} (it refuses to delete a
  // plan that still has Active/Pending/Trial members). Reload and read the
  // persisted list instead.
  await gotoAdminPage(page, "user-registration-membership");
  await expect(page.locator("#wpbody-content")).not.toContainText(name);
}

test.describe("membership plans @fresh", () => {
  test("a free membership plan can be created and appears in the list @fresh @membership", async ({ page }) => {
    await loginAsAdmin(page);
    const name = planName();

    await gotoAdminPage(page, "user-registration-membership", "&action=add_new_membership");

    await page.locator("#ur-input-type-membership-name").fill(name);
    await page.locator("#ur-membership-free-type").check();
    await expect(page.locator("#ur-membership-free-type")).toBeChecked();

    // Publish the plan INACTIVE. An active free plan renders as a radio option
    // on every registration form, so the other specs would register users into
    // it — and a plan with Active members cannot be deleted, which would leave
    // this test unable to clean up after itself and would quietly change the
    // fixture every later spec depends on.
    const status = page.locator("#ur-membership-status");
    if (await status.isChecked()) await status.uncheck();

    // Publish saves over AJAX and then redirects to the list itself. Issuing
    // our own navigation here races that redirect and aborts it, so wait for
    // the redirect the product performs rather than repeating it.
    await page.getByRole("button", { name: "Publish" }).click();
    await page.waitForURL(/page=user-registration-membership(?!.*action=add_new)/, {
      timeout: 30_000,
    });

    // The list is the evidence, not the notice on the editor screen.
    await expect(page.locator("#wpbody-content")).toContainText(name);

    await deletePlan(page, name);
  });
});
