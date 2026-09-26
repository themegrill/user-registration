#!/usr/bin/env node
/**
 * Print the checks that apply to the current change.
 *
 * Usage: node .claude/skills/ur-check/plan-checks.mjs [baseRef]   (default origin/develop)
 * Covers commits since baseRef, uncommitted edits and untracked files. It only plans;
 * it runs nothing.
 */
import { spawnSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const DEFAULT_BASE = "origin/develop";
const SUITE_FILE = ".themegrill-qa/suite.json";
const MAX_LISTED_FILES = 25;
const PHPCS_BATCH_SIZE = 25;
const SAFE_REF = /^[A-Za-z0-9_][A-Za-z0-9._\/-]*$/;

/**
 * Reject a base ref that git could read as an option (`--output=...`) or that carries odd characters.
 *
 * @param {string} ref Ref supplied on the command line.
 * @returns {string} The same ref.
 * @throws {Error} When the ref is not a plain branch, tag or remote-branch name.
 */
export function assertSafeRef(ref) {
	if (!SAFE_REF.test(ref) || ref.includes("..")) {
		throw new Error(`"${ref}" is not a plain ref name (letters, digits, . _ / - only, not starting with a dash).`);
	}
	return ref;
}

/**
 * Quote a path for safe use as a single POSIX shell argument.
 *
 * Paths come from git and can contain spaces or shell metacharacters; the planner's output is a
 * command Claude runs directly, so every path must be quoted before being joined into one.
 *
 * @param {string} p Path to quote.
 * @returns {string} `'...'`, with any embedded `'` escaped.
 */
export function shellQuote(p) {
	return `'${p.replace(/'/g, `'\\''`)}'`;
}

/**
 * Describe a list truncated for display, so nothing is dropped silently.
 *
 * @param {string[]} items Full list.
 * @returns {string[]} Up to MAX_LISTED_FILES lines, plus an "and N more" line when cut.
 */
export function listWithOverflow(items) {
	const lines = items.slice(0, MAX_LISTED_FILES).map((f) => `  ${f}`);
	if (items.length > MAX_LISTED_FILES) lines.push(`  ... and ${items.length - MAX_LISTED_FILES} more`);
	return lines;
}

const REVIEW_PATHS =
	/^(modules\/(membership|content-restriction|content-drip|stripe|paypal|payment-history)\/|includes\/(class-ur-ajax|class-ur-form-handler|class-ur-install|class-ur-email-|class-ur-user-approval|frontend\/|RestApi\/)|includes\/functions-ur-(core|update)\.php)/;
const CONTRACT_REMOVAL =
	/^-.*\b(apply_filters|do_action|add_shortcode|register_rest_route|register_post_type|do_action_ref_array)\s*\(|^-.*['"]wp_ajax_(nopriv_)?[a-z_]+/m;

/**
 * Convert a suite.json glob (`*`, `**`) to a RegExp; matching is case-insensitive.
 *
 * @param {string} glob
 * @returns {RegExp}
 */
export function globToRegExp(glob) {
	const body = glob
		.replace(/[.+^${}()|[\]\\]/g, "\\$&")
		.replace(/\*\*/g, "\u0000")
		.replace(/\*/g, "[^/]*")
		.replace(/\u0000/g, ".*");
	return new RegExp(`^${body}$`, "i");
}

/**
 * Decide which checks apply to a set of changed files.
 *
 * @param {string[]} files Forward-slash paths relative to the repo root.
 * @param {Record<string, string[]>} areaPaths `area_paths` from suite.json.
 * @param {string} diffText Unified diff used only to spot removed public-contract lines.
 * @returns {{commands: string[], notes: string[], e2eAreas: string[], unmapped: string[]}}
 */
export function planChecks(files, areaPaths, diffText = "") {
	const commands = [];
	const notes = [];
	const php = files.filter((f) => f.endsWith(".php") && !/^(vendor|includes\/libraries)\//.test(f));
	const frontendSrc = files.filter((f) => /^src\/.*\.(ts|tsx|js|jsx)$/.test(f));
	const scss = files.filter((f) => f.endsWith(".scss"));
	const ts = files.filter((f) => /\.(ts|tsx)$/.test(f));
	const legacyJs = files.filter((f) => /^assets\/.*\.js$/.test(f) && !f.endsWith(".min.js"));

	if (php.length) {
		for (let i = 0; i < php.length; i += PHPCS_BATCH_SIZE) {
			commands.push(`php vendor/bin/phpcs -s ${php.slice(i, i + PHPCS_BATCH_SIZE).map(shellQuote).join(" ")}`);
		}
		notes.push("CI sniffs every changed PHP file in full (report-only, not blocking); the local hook reports changed lines only.");
	}
	if (files.some((f) => /^\.claude\/(hooks|skills\/ur-check)\/.*\.mjs$/.test(f))) {
		commands.push("node --test .claude/hooks/hooks.test.mjs .claude/skills/ur-check/plan-checks.test.mjs   # the Claude Code setup's own tests");
	}
	if (frontendSrc.length || scss.length) commands.push("pnpm prettier");
	if (ts.length) commands.push("pnpm typecheck");
	if (legacyJs.length) commands.push("pnpm exec grunt js   # regenerates *.min.js; commit the outputs");
	if (scss.length) commands.push("pnpm exec grunt css   # regenerates css, rtl and maps; commit the outputs");

	const matchers = Object.entries(areaPaths).map(([area, globs]) => [area, globs.map(globToRegExp)]);
	const e2eAreas = matchers.filter(([, res]) => files.some((f) => res.some((re) => re.test(f)))).map(([a]) => a);
	const unmapped = php
		.concat(files.filter((f) => /^templates\//.test(f)))
		.filter((f) => !matchers.some(([, res]) => res.some((re) => re.test(f))));
	if (e2eAreas.length) commands.push(`pnpm test:e2e   # areas touched: ${e2eAreas.join(", ")} (needs a live site; ask before running)`);

	const review = files.filter((f) => REVIEW_PATHS.test(f));
	if (review.length) notes.push(`Security-sensitive paths changed (${review.slice(0, 5).join(", ")}${review.length > 5 ? ", ..." : ""}): run the ur-reviewer agent.`);
	if (CONTRACT_REMOVAL.test(diffText)) notes.push("The diff removes a hook, shortcode, REST route, post type or AJAX action line: this may break a public contract. Confirm it is intentional and shimmed.");
	if (php.length) notes.push("No PHP unit tests exist in this repo; do not report them as run.");
	return { commands, notes, e2eAreas, unmapped };
}

function git(args) {
	const r = spawnSync("git", args, { encoding: "utf8", maxBuffer: 64 * 1024 * 1024 });
	if (r.status !== 0) throw new Error(`git ${args.join(" ")} failed: ${r.stderr.trim()}`);
	return r.stdout;
}

function changedFiles(base) {
	const lines = [
		...git(["diff", "--name-only", "--diff-filter=d", `${base}...HEAD`]).split("\n"),
		...git(["diff", "--name-only", "--diff-filter=d", "HEAD"]).split("\n"),
		...git(["ls-files", "--others", "--exclude-standard"]).split("\n"),
	];
	return [...new Set(lines.map((l) => l.trim()).filter(Boolean))];
}

function main() {
	const base = assertSafeRef(process.argv[2] || DEFAULT_BASE);
	const files = changedFiles(base);
	if (!files.length) {
		console.log(`No changes against ${base}.`);
		return;
	}
	const suite = JSON.parse(fs.readFileSync(path.join(process.cwd(), SUITE_FILE), "utf8"));
	const diffText = git(["diff", "-U0", `${base}...HEAD`]) + git(["diff", "-U0", "HEAD"]);
	const plan = planChecks(files, suite.area_paths ?? {}, diffText);

	console.log(`Changed files (${files.length}) vs ${base}:\n${listWithOverflow(files).join("\n")}`);
	console.log(`\nRun:\n${plan.commands.length ? plan.commands.map((c) => `  ${c}`).join("\n") : "  (no automated check applies)"}`);
	if (plan.unmapped.length) console.log(`\nNo e2e area in ${SUITE_FILE} covers (so CI runs no spec for them):\n${listWithOverflow(plan.unmapped).join("\n")}`);
	if (plan.notes.length) console.log(`\nNotes:\n${plan.notes.map((n) => `  - ${n}`).join("\n")}`);
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
	try {
		main();
	} catch (err) {
		console.error(`plan-checks failed: ${err.message}`);
		process.exit(1);
	}
}
