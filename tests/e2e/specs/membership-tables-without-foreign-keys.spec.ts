import { test, expect } from "@playwright/test";
import { execFileSync } from "node:child_process";
import path from "node:path";

// This spec executes a DB-backed PHP regression on a disposable site; it never touches a live one.
test("membership tables exist without foreign keys and deletes still cascade @fresh @membership", () => {
	const wordpress = process.env.UR_SECURITY_WP_PATH;
	test.skip(
		!wordpress,
		"Set UR_SECURITY_WP_PATH to a disposable WordPress install with this branch active."
	);
	const result = execFileSync(
		"wp",
		[
			"eval-file",
			path.resolve(
				__dirname,
				"../support/membership-tables-integration.php"
			),
			`--path=${wordpress}`
		],
		{
			encoding: "utf8",
			timeout: 60_000,
			env: { ...process.env, UR_SECURITY_DISPOSABLE: "1" }
		}
	);
	expect(result).toContain("18 assertions passed");
});
