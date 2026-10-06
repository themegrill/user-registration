import { expect, test } from "@playwright/test";
import { gotoAdminPage, loginAsAdmin, restNonce } from "../support/wp";

/**
 * #1745 / #1537 — Site Assistant: Link Existing Users
 * Validates the Site Assistant linking flow and API guards for unlinked accounts.
 */
test.describe("Site Assistant: Link Existing Users @fresh @admin", () => {
	test.beforeEach(async ({ page }) => {
		await loginAsAdmin(page);
	});

	test("Site Assistant migrate_existing_users rejects draft or invalid forms via AJAX @fresh @admin", async ({
		page,
	}) => {
		const nonce = await restNonce(page);

		const response = await page.evaluate(async (nonce) => {
			const body = new URLSearchParams({
				action: "user_registration_migrate_existing_users",
				form_id: "99999999",
				security: nonce,
			});

			const res = await fetch("/wp-admin/admin-ajax.php", {
				method: "POST",
				headers: {
					"Content-Type": "application/x-www-form-urlencoded",
				},
				body: body.toString(),
			});

			return await res.json();
		}, nonce);

		expect(response.success).toBe(false);
		expect(response.data?.message).toMatch(/Invalid or unpublished registration form selected/i);
	});

	test("Site Assistant skip_site_assistant_section handles migrate_users action @fresh @admin", async ({
		page,
	}) => {
		const nonce = await restNonce(page);

		const response = await page.evaluate(async (nonce) => {
			const body = new URLSearchParams({
				action: "user_registration_skip_site_assistant_section",
				section: "migrate_users",
				security: nonce,
			});

			const res = await fetch("/wp-admin/admin-ajax.php", {
				method: "POST",
				headers: {
					"Content-Type": "application/x-www-form-urlencoded",
				},
				body: body.toString(),
			});

			return await res.json();
		}, nonce);

		expect(response.success).toBe(true);
		expect(response.data?.message).toMatch(/Linking existing users step has been skipped/i);
	});
});
