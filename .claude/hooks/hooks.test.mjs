// Run with: node --test .claude/hooks/
import assert from "node:assert/strict";
import { test } from "node:test";
import { classify, toRelative } from "./guard-files.mjs";
import { extractJson, filterToChanged, parseChangedRanges } from "./phpcs-changed.mjs";
import { projectRootFor } from "./project-root.mjs";
import { spawnSync } from "node:child_process";
import os from "node:os";
import path from "node:path";

const norm = (p) => path.resolve(p).toLowerCase();
const repoRoot = spawnSync("git", ["rev-parse", "--show-toplevel"], { encoding: "utf8" }).stdout.trim();

test("root: a file inside the repo resolves to the repo root, even if its directory does not exist yet", () => {
	assert.equal(norm(projectRootFor(path.join(repoRoot, "includes", "a.php"), {}, "/nowhere")), norm(repoRoot));
	assert.equal(norm(projectRootFor(path.join(repoRoot, "brand", "new", "dir", "a.php"), {}, "/nowhere")), norm(repoRoot));
});

test("root: the file's own repo wins over a different CLAUDE_PROJECT_DIR (git worktree case)", () => {
	const elsewhere = path.join(os.tmpdir(), "not-this-project");
	assert.equal(norm(projectRootFor(path.join(repoRoot, "vendor", "x.php"), { cwd: elsewhere }, elsewhere)), norm(repoRoot));
});

test("root: a file outside any repository falls back to the given root", () => {
	const outside = path.join(os.tmpdir(), "no-repo-here", "a.php");
	const fallback = path.join(os.tmpdir(), "fallback-root");
	const got = projectRootFor(outside, {}, fallback);
	assert.ok([norm(fallback), norm(os.tmpdir())].includes(norm(got)) || !norm(got).startsWith(norm(repoRoot)), got);
});

test("phpcs: deprecation noise before the JSON report is ignored", () => {
	const out = 'DEPRECATED: x\r\nfoo\r\n{"totals":{"errors":1},"files":{}}';
	assert.deepEqual(JSON.parse(extractJson(out)).totals, { errors: 1 });
});

const never = () => false;
const always = () => true;

test("guard: generated and vendored paths are denied", () => {
	for (const p of [
		"vendor/autoload.php",
		"node_modules/x/index.js",
		"chunks/a.js",
		"assets/js/admin/admin.min.js",
		"assets/css/admin-rtl.css",
		"assets/css/admin.css.map",
		"languages/user-registration.pot",
		".themegrill-qa/.env.local",
		".themegrill-qa/docs/membership.md",
	]) {
		assert.equal(classify(p, never)?.decision, "deny", p);
	}
});

test("guard: differently-cased paths cannot bypass it (case-insensitive filesystems)", () => {
	for (const p of ["Vendor/autoload.php", "Languages/user-registration.pot", "Chunks/a.js", "assets/js/admin/ADMIN.MIN.JS", "ASSETS/CSS/Admin-RTL.css", ".ThemeGrill-QA/.env.local"]) {
		assert.equal(classify(p, never)?.decision, "deny", p);
	}
	assert.equal(classify("changelog.txt", never)?.decision, "ask");
	assert.equal(classify("Assets/CSS/Admin.CSS", always)?.decision, "deny");
});

test("guard: compiled css is denied only when a scss source exists", () => {
	assert.equal(classify("assets/css/admin.css", always)?.decision, "deny");
	assert.equal(classify("assets/css/ur-toast.css", never), null);
});

test("guard: team-owned files ask", () => {
	for (const p of ["CHANGELOG.txt", "pnpm-lock.yaml", "composer.lock", "includes/libraries/x.php", ".github/workflows/release.yml"]) {
		assert.equal(classify(p, never)?.decision, "ask", p);
	}
});

test("guard: normal source is allowed", () => {
	for (const p of ["includes/class-ur-ajax.php", "src/dashboard/index.tsx", "assets/js/admin/admin.js", "templates/form-registration.php"]) {
		assert.equal(classify(p, never), null, p);
	}
});

test("guard: toRelative handles absolute, windows and outside paths", () => {
	const root = process.platform === "win32" ? "C:\\proj" : "/proj";
	const inside = process.platform === "win32" ? "C:\\proj\\includes\\a.php" : "/proj/includes/a.php";
	const outside = process.platform === "win32" ? "C:\\other\\a.php" : "/other/a.php";
	assert.equal(toRelative(inside, root), "includes/a.php");
	assert.equal(toRelative("includes/a.php", root), "includes/a.php");
	assert.equal(toRelative(outside, root), null);
});

test("phpcs: diff hunks become new-side line ranges", () => {
	const diff = "@@ -1,2 +1,3 @@\n+a\n@@ -10 +12 @@\n+b\n@@ -20,3 +25,0 @@\n-c\n";
	assert.deepEqual(parseChangedRanges(diff), [[1, 3], [12, 12]]);
});

test("phpcs: only messages on changed lines survive; null ranges keeps all", () => {
	const msgs = [{ line: 2 }, { line: 11 }, { line: 12 }];
	assert.deepEqual(filterToChanged(msgs, [[1, 3], [12, 12]]), [{ line: 2 }, { line: 12 }]);
	assert.equal(filterToChanged(msgs, null).length, 3);
});
