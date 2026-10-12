import { expect, test } from "@playwright/test";
import { ensureFirstRun } from "../support/urm";
import { gotoAdminPage, loginAsAdmin } from "../support/wp";

/**
 * Regression spec for Issue #1674:
 * Multiple Registration discoverability for single-form setups.
 */
test.describe("multiple registration discoverability @fresh", () => {
	test.beforeEach(async ({ page }) => {
		await loginAsAdmin(page);
	});

	test("shows contextual Add New in sidebar when single form exists and module is inactive @fresh @admin", async ({
		page
	}) => {
		await ensureFirstRun(page);
		await gotoAdminPage(page, "user-registration");

		const menu = page.locator("#adminmenu");
		const addNewItem = menu.locator(
			'a[href*="page=add-new-registration"].ur-activate-dependent-module'
		);
		await expect(addNewItem).toBeVisible();

		const submenuItems = menu.locator(
			"#toplevel_page_user-registration .wp-submenu li a"
		);
		const itemTexts = await submenuItems.allInnerTexts();
		const regIndex = itemTexts.findIndex((text) =>
			/Registration Form/i.test(text)
		);
		const addNewIndex = itemTexts.findIndex((text) =>
			/Add New/i.test(text)
		);
		expect(addNewIndex).toBe(regIndex + 1);

		await addNewItem.click();
		await expect(
			page.locator(".user-registration-swal2-modal")
		).toBeVisible();
		await expect(
			page.locator(".user-registration-swal2-modal")
		).toContainText(/activate/i);
	});

	/**
	 * Regression spec for Issue #1794: the redirect used to run inside the
	 * page's render callback, after headers were already sent, so a direct
	 * visit landed on a blank page instead of the form editor + modal.
	 */
	test("opening Add New by direct URL still redirects to the form editor and opens the modal @fresh @admin", async ({
		page
	}) => {
		await ensureFirstRun(page);
		await gotoAdminPage(page, "add-new-registration");

		await expect(page).toHaveURL(/[?&]edit-registration=\d+/);
		await expect(
			page.locator(".user-registration-swal2-modal")
		).toBeVisible();
		await expect(
			page.locator(".user-registration-swal2-modal")
		).toContainText(/activate/i);
	});

	test("never shows Add New on the Login Form page, since Multiple Registration cannot add a second login form @fresh @admin", async ({
		page
	}) => {
		await ensureFirstRun(page);
		await gotoAdminPage(page, "user-registration-login-forms");

		const menu = page.locator("#adminmenu");
		const addNewItem = menu.locator(
			'a[href*="page=add-new-registration"].ur-activate-dependent-module'
		);
		await expect(addNewItem).toHaveCount(0);
	});
});
