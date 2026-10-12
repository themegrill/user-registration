import { expect, test } from "@playwright/test";
import { readFile } from "node:fs/promises";
import path from "node:path";

const root = path.resolve(__dirname, "../../..");

/**
 * #1677 — close (x) must dismiss the ThemeGrill SDK feedback modal without
 * following the plugins-list Deactivate URL. Asserted against source so CI
 * does not need the SDK modal bootstrapped in Playground.
 */
test.describe("deactivation popup close @fresh", () => {
	test("X dismisses without navigating to the deactivate URL @fresh @admin", async () => {
		const js = await readFile(
			path.join(root, "assets/js/admin/urm-deactivation-popup.js"),
			"utf8",
		);

		expect(js, "close handler must preventDefault").toMatch(
			/\$close\.on\(\s*["']click["']\s*,\s*function\s*\(\s*e\s*\)\s*\{[\s\S]*?e\.preventDefault\(\)/,
		);
		expect(
			js,
			"close handler must not assign window.location from the Deactivate link",
		).not.toMatch(/window\.location\.href\s*=\s*\$target/);
		expect(js, "close must only toggle modal/body classes").toMatch(
			/\$popup\.removeClass\(\s*["']active["']\s*\)/,
		);
	});

	test("popupId is localised from the plugin folder slug @fresh @admin", async () => {
		const php = await readFile(
			path.join(root, "includes/admin/class-ur-sdk-deactivation-feedback.php"),
			"utf8",
		);

		expect(php).toMatch(/'popupId'\s*=>\s*\$this->plugin_slug\s*\.\s*'_uninstall_feedback_popup'/);
		expect(php).toMatch(
			/\$this->product_key\s*\.\s*'_feedback_deactivate_button_submit'/,
		);
	});
});
