import { expect, test } from "@playwright/test";
import { gotoAdminPage, loginAsAdmin, restNonce } from "../support/wp";

/**
 * #1516 task 3 — CDN templates that still carry form-level payment fields stay
 * off Add New for every site. Existing forms that already have those fields keep
 * them; a new form never picks them up through a template.
 */
const LEGACY_PAYMENT_TEMPLATE_SLUGS = [
	"pre-order-form",
	"e-learning-registration-form",
	"course-registration-form",
	"donation-form",
	"paypal-event-registration-form",
	"conference-registration-form",
	"car-race-registration-form",
] as const;

const LEGACY_PAYMENT_TEMPLATE_TITLES = [
	"Pre-order Form",
	"E-Learning Registration Form",
	"Course Registration Form",
	"Donation Form",
	"PayPal Event Registration Form",
	"Conference Registration Form",
	"Car Race Registration Form",
] as const;

async function fetchTemplateSlugs(page: import("@playwright/test").Page, nonce: string): Promise<string[]> {
	return page.evaluate(async (nonce) => {
		const res = await fetch("/wp-json/user-registration/v1/form-templates", {
			headers: { "X-WP-Nonce": nonce },
			credentials: "same-origin",
		});
		if (!res.ok) {
			throw new Error(`form-templates HTTP ${res.status}`);
		}
		const data = await res.json();
		const sections = Array.isArray(data?.templates) ? data.templates : [];
		return sections.flatMap((section: { templates?: { slug?: string }[] }) =>
			Array.isArray(section.templates)
				? section.templates.map((t) => t.slug).filter(Boolean)
				: [],
		);
	}, nonce);
}

test.describe("legacy payment templates @fresh", () => {
	test.beforeEach(async ({ page }) => {
		await loginAsAdmin(page);
	});

	test("Add New never lists CDN templates that use legacy payment fields @fresh @admin", async ({
		page,
	}) => {
		const nonce = await restNonce(page);
		const slugs = await fetchTemplateSlugs(page, nonce);

		expect(slugs.length).toBeGreaterThan(0);
		for (const slug of LEGACY_PAYMENT_TEMPLATE_SLUGS) {
			expect(slugs, `expected ${slug} hidden from Add New`).not.toContain(slug);
		}

		await gotoAdminPage(page, "add-new-registration");
		await expect(page.locator("#user-registration-form-templates")).toBeVisible({
			timeout: 30_000,
		});
		await expect(page.getByText("Start From Scratch").first()).toBeVisible({
			timeout: 45_000,
		});

		for (const title of LEGACY_PAYMENT_TEMPLATE_TITLES) {
			await expect(
				page.locator("#user-registration-form-templates").getByText(title, { exact: true }),
			).toHaveCount(0);
		}
	});
});
