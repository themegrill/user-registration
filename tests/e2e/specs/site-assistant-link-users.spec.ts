import { expect, test } from "@playwright/test";
import { STRONG_PASSWORD, uniqueEmail, uniqueUsername } from "../support/env";
import { ensureFirstRun, firstFormId } from "../support/urm";
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

	test("Site Assistant migrate_existing_users never links a user twice when requests overlap @fresh @admin", async ({
		page,
	}) => {
		await ensureFirstRun(page);
		const formId = await firstFormId(page);
		const nonce = await restNonce(page);
		const spareUsers = Array.from({ length: 6 }, () => ({
			username: uniqueUsername("link"),
			email: uniqueEmail("link"),
		}));

		const outcome = await page.evaluate(
			async ({ nonce, formId, spareUsers, password }) => {
				const link = async () => {
					const res = await fetch("/wp-admin/admin-ajax.php", {
						method: "POST",
						headers: { "Content-Type": "application/x-www-form-urlencoded" },
						body: new URLSearchParams({
							action: "user_registration_migrate_existing_users",
							form_id: String(formId),
							security: nonce,
						}).toString(),
					});
					return await res.json();
				};

				// Link anyone already unlinked, so only the users created below are left to link.
				await link();

				const createdIds: number[] = [];
				for (const user of spareUsers) {
					const res = await fetch("/wp-json/wp/v2/users", {
						method: "POST",
						headers: { "X-WP-Nonce": nonce, "Content-Type": "application/json" },
						credentials: "same-origin",
						body: JSON.stringify({ ...user, password, roles: ["subscriber"] }),
					});
					createdIds.push((await res.json()).id);
				}

				const responses = await Promise.all([link(), link(), link(), link()]);

				for (const id of createdIds) {
					await fetch(`/wp-json/wp/v2/users/${id}?force=true&reassign=1`, {
						method: "DELETE",
						headers: { "X-WP-Nonce": nonce },
						credentials: "same-origin",
					});
				}

				return {
					created: createdIds.filter(Boolean).length,
					linkedPerRequest: responses.map((response) => (response.success ? response.data.count : 0)),
				};
			},
			{ nonce, formId, spareUsers, password: STRONG_PASSWORD },
		);

		const totalLinked = outcome.linkedPerRequest.reduce((sum: number, count: number) => sum + count, 0);

		expect(outcome.created).toBe(spareUsers.length);
		// Every user is linked exactly once. A race that links a user in two requests pushes this above the user count.
		expect(totalLinked).toBe(spareUsers.length);
	});
});
