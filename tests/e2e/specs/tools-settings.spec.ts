import { expect, test } from "@playwright/test";
import {
	ensureFirstRun,
	firstFormId,
	registerOn,
	registrationPageFor
} from "../support/urm";
import {
	deleteUserByEmail,
	gotoAdminPage,
	loginAsAdmin,
	newVisitor
} from "../support/wp";

/**
 * Regression coverage for #1738 (Tools rebuilt to match Settings UI): the
 * legacy standalone Tools page is gone, so its old URLs, the Logs list it
 * used to render, and a log's delete action all have to keep working from
 * their new home inside the Settings rail.
 */
test.describe("tools settings parity @fresh", () => {
	test.beforeEach(async ({ page }) => {
		await loginAsAdmin(page);
	});

	test("legacy Tools URL redirects into the Settings rail @fresh @admin", async ({
		page
	}) => {
		await page.goto(
			"/wp-admin/admin.php?page=user-registration-status&tab=logs"
		);
		await page.waitForLoadState("domcontentloaded");

		expect(page.url()).toContain("page=user-registration-settings");
		expect(page.url()).toContain("tab=tools");
		expect(page.url()).toContain("section=logs");
		await expect(
			page
				.locator(
					".ur-log-card"
				)
				.first()
		).toBeVisible();
	});

	test("Logs section renders under Tools @fresh @admin", async ({ page }) => {
		await gotoAdminPage(
			page,
			"user-registration-settings",
			"&tab=tools&section=logs"
		);

		await expect(
			page.locator(".user-registration-settings--tools")
		).toBeVisible();
		// Which view renders depends on how many log sources exist: none shows the
		// empty state, exactly one opens that log directly, and two or more show the
		// table. All three sit in a log card, so that is the stable thing to assert.
		await expect(
			page
				.locator(
					".ur-log-card"
				)
				.first()
		).toBeVisible();
	});

	test("a single log can be deleted through the confirmation modal @fresh @admin", async ({
		page,
		browser
	}) => {
		// Seed a real log entry rather than a fixture: WordPress Playground has no
		// outbound mail, so the registration notification UR_Emailer sends here
		// fails, and UR_Emailer logs that failure under the `ur_mail_logs` handle
		// ("Email Delivery" in the UI) — the same write path a real site hits.
		await ensureFirstRun(page);
		const formId = await firstFormId(page);
		const url = await registrationPageFor(page, formId);

		const visitor = await newVisitor(browser);
		const guest = await visitor.newPage();
		const account = await registerOn(guest, url);
		// Wait for the registration request to actually complete server-side
		// (and the email attempt with it) before trusting the log exists.
		await expect(
			guest
				.locator(".ur-message, .user-registration-message, .ur-error")
				.first()
		).toBeVisible();
		await visitor.close();

		// Open that log by its handle rather than looking for a table row: the list
		// only renders when two or more logs exist, and a site with exactly one opens
		// it directly. Targeting the handle works either way.
		await gotoAdminPage(
			page,
			"user-registration-settings",
			"&tab=tools&section=logs&log=ur_mail_logs"
		);
		const header = page.locator(".ur-log-single-header", {
			hasText: "Email Delivery"
		});
		await expect(header).toBeVisible({ timeout: 30_000 });

		await header.locator(".ur-log-delete-link").click();

		const modal = page.locator(".user-registration-swal2-modal");
		await expect(modal).toBeVisible();
		await expect(modal).toContainText("Delete Log");
		await modal.locator(".swal2-confirm").click();

		await page.waitForLoadState("domcontentloaded");
		await expect(
			page.locator(".ur-log-card-header", { hasText: "Email Delivery" })
		).toHaveCount(0);
		await expect(
			page.locator(".row-title", { hasText: "Email Delivery" })
		).toHaveCount(0);

		await deleteUserByEmail(page, account.email);
	});
});
