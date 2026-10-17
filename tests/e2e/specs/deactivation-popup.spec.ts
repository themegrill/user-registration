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

	/**
	 * pro#1950 — Loader::$labels is shared by every ThemeGrill SDK product.
	 * Replacing its uninstall options drops other products' ids (Everest Forms'
	 * id6) and fatals the Plugins page on PHP 8, so URM's labels must be merged.
	 */
	test("URM merges, not replaces, the shared SDK uninstall labels @fresh @admin", async () => {
		const php = await readFile(
			path.join(root, "includes/admin/class-ur-sdk-deactivation-feedback.php"),
			"utf8",
		);
		const method = php.match(
			/function\s+patch_sdk_labels_after_init\s*\(\s*\)\s*\{[\s\S]*?\n\t\t\}/,
		);

		expect(method, "patch_sdk_labels_after_init() must exist").not.toBeNull();

		const body = method![0];
		const assignments = [
			...body.matchAll(/Loader::\$labels\['uninstall'\]\['options'\]\s*=(?!=)\s*([^;]+);/g),
		];

		expect(assignments.length, "shared options must be written exactly once").toBe(1);
		expect(
			assignments[0][1],
			"existing labels must be the first array_merge argument so other products keep theirs",
		).toMatch(/^array_merge\(\s*\\?ThemeGrillSDK\\Loader::\$labels\['uninstall'\]\['options'\]\s*,/);
	});
});
