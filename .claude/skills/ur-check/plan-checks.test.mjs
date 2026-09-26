// Run with: node --test .claude/skills/ur-check/plan-checks.test.mjs
import assert from "node:assert/strict";
import { test } from "node:test";
import { assertSafeRef, globToRegExp, listWithOverflow, planChecks } from "./plan-checks.mjs";

test("base ref: option-like and odd refs are rejected, normal refs pass", () => {
	for (const bad of ["--output=/tmp/x", "-p", "a..b", "a b", "a;rm", "", "$(x)"]) {
		assert.throws(() => assertSafeRef(bad), /not a plain ref/, bad);
	}
	for (const ok of ["origin/develop", "master", "release/v5.2.8", "v5.2.8", "HEAD"]) assert.equal(assertSafeRef(ok), ok);
});

test("more than one batch of php files is split, never truncated", () => {
	const files = Array.from({ length: 60 }, (_, i) => `includes/f${i}.php`);
	const cmds = planChecks(files, {}).commands.filter((c) => c.startsWith("php vendor/bin/phpcs"));
	assert.equal(cmds.length, 3);
	assert.equal(cmds.join(" ").split(" ").filter((x) => x.endsWith(".php")).length, 60);
});

test("long listings say how many were cut", () => {
	assert.equal(listWithOverflow(["a", "b"]).length, 2);
	const out = listWithOverflow(Array.from({ length: 30 }, (_, i) => `f${i}`));
	assert.equal(out.at(-1), "  ... and 5 more");
});

const areas = {
	admin: ["includes/admin/settings/**", "includes/admin/class-ur-admin-menus.php"],
	registration: ["includes/class-ur-ajax.php", "templates/**"],
};

test("glob: ** crosses directories, * does not", () => {
	assert.ok(globToRegExp("includes/admin/settings/**").test("includes/admin/settings/a/b.php"));
	assert.ok(globToRegExp("templates/*.php").test("templates/x.php"));
	assert.ok(!globToRegExp("templates/*.php").test("templates/sub/x.php"));
	assert.ok(!globToRegExp("a.php").test("aXphp"));
});

test("php change: phpcs, e2e area, security review note, no-phpunit reminder", () => {
	const p = planChecks(["includes/class-ur-ajax.php"], areas);
	assert.ok(p.commands.some((c) => c.startsWith("php vendor/bin/phpcs")));
	assert.deepEqual(p.e2eAreas, ["registration"]);
	assert.ok(p.notes.some((n) => n.includes("ur-reviewer")));
	assert.ok(p.notes.some((n) => n.includes("No PHP unit tests")));
});

test("unmapped php is reported so the gap is visible", () => {
	const p = planChecks(["includes/class-ur-cron.php"], areas);
	assert.deepEqual(p.unmapped, ["includes/class-ur-cron.php"]);
	assert.deepEqual(p.e2eAreas, []);
});

test("frontend: ts change needs prettier and typecheck; legacy js and scss need grunt", () => {
	const p = planChecks(["src/a/b.tsx", "assets/js/admin/x.js", "assets/js/admin/x.min.js", "assets/css/y.scss"], areas);
	for (const c of ["pnpm prettier", "pnpm typecheck"]) assert.ok(p.commands.includes(c), c);
	assert.equal(p.commands.filter((c) => c.startsWith("pnpm exec grunt js")).length, 1);
	assert.equal(p.commands.filter((c) => c.startsWith("pnpm exec grunt css")).length, 1);
});

test("removed hook line is flagged as a possible contract break", () => {
	const diff = "@@ -1 +0,0 @@\n-\tdo_action( 'user_registration_after_x', $a );\n";
	assert.ok(planChecks(["includes/a.php"], areas, diff).notes.some((n) => n.includes("public contract")));
	assert.ok(!planChecks(["includes/a.php"], areas, "+\tdo_action( 'x' );\n").notes.some((n) => n.includes("public contract")));
});

test("editing a hook or the plan script recommends the setup's own tests", () => {
	const cmds = planChecks([".claude/hooks/guard-files.mjs"], areas).commands;
	assert.ok(cmds.some((c) => c.startsWith("node --test")));
	assert.deepEqual(planChecks([".claude/rules/php.md"], areas).commands, []);
});

test("vendored php is not sniffed", () => {
	assert.deepEqual(planChecks(["vendor/x/y.php", "includes/libraries/z.php"], areas).commands, []);
});
