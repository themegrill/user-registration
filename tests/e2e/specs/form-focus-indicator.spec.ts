import { expect, test, type Locator } from "@playwright/test";
import { wpCli, wpCliAvailable } from "../support/cli";
import { newVisitor } from "../support/wp";

/**
 * Every form template, with the theme style it ships under: only "Default" with
 * the plugin's default theme style loads its own stylesheet, which sets the
 * dropdown border at a specificity that also beats the generic focus rule.
 */
const CASES = [
	{ template: "Default", themeStyle: "default" },
	{ template: "Bordered", themeStyle: "theme" },
	{ template: "Flat", themeStyle: "theme" },
	{ template: "Rounded", themeStyle: "theme" },
	{ template: "Rounded Edge", themeStyle: "theme" }
];

const SELECT_NAME = "qa_focus_select";
const TEXTAREA_NAME = "qa_focus_textarea";

/** A minimal field definition in the shape the form builder stores. */
function field(key: string, name: string, extra: Record<string, unknown> = {}) {
	return {
		field_key: key,
		general_setting: {
			label: name,
			description: "",
			placeholder: "",
			required: "0",
			hide_label: "false",
			field_name: name,
			tooltip: "false",
			tooltip_message: "",
			...extra
		},
		advance_setting: { custom_class: "", field_visibility: "both" }
	};
}

/** Focuses the control and waits until its border colour changes or an outline is drawn. */
async function expectFocusIndicator(
	control: Locator,
	message: string
): Promise<void> {
	const read = () =>
		control.evaluate((el) => {
			const style = getComputedStyle(el);
			return {
				border: style.borderTopColor,
				outline:
					style.outlineStyle !== "none" &&
					parseFloat(style.outlineWidth) > 0
			};
		});

	const unfocused = await read();
	await control.focus();
	// Rounded templates animate the border, so the changed colour only settles after a frame or two.
	await expect
		.poll(
			async () => {
				const focused = await read();
				return focused.outline || focused.border !== unfocused.border;
			},
			{ message }
		)
		.toBe(true);
}

/**
 * themegrill/user-registration-pro#1896 — focus on text fields moved from a
 * border plus a 2px ring to the border alone. The dropdown's border rule was the
 * one without `!important`, so any form template's more specific grey border
 * beat it and a focused dropdown looked unfocused (WCAG 2.4.7).
 */
test.describe("front-end focus indicator by form template @fresh", () => {
	/**
	 * @area    registration-forms
	 * @tier    fresh
	 * @guards  #1896
	 * @source  verify-fix 2026-10-11
	 * @why     With a form template set, each template's grey border outranked the
	 *          dropdown's focus border, so a keyboard user focusing a dropdown saw
	 *          no change at all. Asserts a dropdown and a textarea each show an
	 *          indicator on every template, not what colour it is. Builds its own
	 *          form (the CPT has no REST route) and skips where wp-cli is
	 *          unavailable. Does not cover the login form or Pro fields.
	 */
	test("a focused dropdown and textarea show a focus indicator on every form template @fresh @registration-forms", async ({
		browser
	}) => {
		test.skip(
			!wpCliAvailable(),
			"wp-cli is not reachable in this environment"
		);

		const existingForm = wpCli([
			"post",
			"list",
			"--post_type=user_registration",
			"--post_status=publish",
			"--posts_per_page=1",
			"--field=post_content"
		]);
		expect(
			existingForm,
			"a registration form must already exist to copy the fixture from"
		).not.toBe("");
		const rows = JSON.parse(existingForm);
		rows[0][0].push(
			field("select", SELECT_NAME, {
				options: ["Option 1", "Option 2"],
				image_choice: "false"
			}),
			field("textarea", TEXTAREA_NAME)
		);

		const formId = Number(
			wpCli([
				"post",
				"create",
				"--post_type=user_registration",
				"--post_title=QA Focus Indicator Form",
				"--post_status=publish",
				`--post_content=${JSON.stringify(rows)}`,
				"--porcelain"
			])
		);
		let pageId: number | null = null;
		const visitor = await newVisitor(browser);
		try {
			pageId = Number(
				wpCli([
					"post",
					"create",
					"--post_type=page",
					"--post_title=QA Focus Indicator",
					"--post_name=qa-focus-indicator",
					"--post_status=publish",
					`--post_content=[user_registration_form id="${formId}"]`,
					"--porcelain"
				])
			);
			const guest = await visitor.newPage();
			for (const { template, themeStyle } of CASES) {
				wpCli(["post", "meta", "update", String(formId), "user_registration_form_template", template]);
				wpCli(["post", "meta", "update", String(formId), "user_registration_enable_theme_style", themeStyle]);
				await guest.goto(`/?page_id=${pageId}`);

				for (const name of [SELECT_NAME, TEXTAREA_NAME]) {
					const control = guest.locator(`[name="${name}"]`);
					await expect(
						control,
						`${name} must render on the ${template} template`
					).toBeVisible();
					await expectFocusIndicator(
						control,
						`${name} must show a focus indicator on the ${template} template`
					);
				}
			}
		} finally {
			await visitor.close();
			if (pageId) wpCli(["post", "delete", String(pageId), "--force"]);
			wpCli(["post", "delete", String(formId), "--force"]);
		}
	});
});
