#!/usr/bin/env node
/**
 * PostToolUse hook: run the repo's phpcs ruleset on an edited PHP file and report only
 * violations on lines changed since HEAD.
 *
 * Whole-file reporting is deliberately avoided: legacy files carry pre-existing violations
 * (CI's sniff job is red on them), so it would bury the few that Claude just introduced.
 * Non-blocking: results go back to Claude as context.
 */
import { spawnSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { projectRootFor } from "./project-root.mjs";

const MAX_REPORTED = 12;
const PHPCS_TIMEOUT_MS = 45000;
const INTERNAL_ERROR_SOURCE = "Internal.Exception";

/**
 * Parse `git diff -U0` output into the new-side line ranges it touches.
 *
 * @param {string} diff Unified diff with zero context.
 * @returns {Array<[number, number]>} Inclusive [start, end] ranges.
 */
export function parseChangedRanges(diff) {
	const ranges = [];
	for (const m of diff.matchAll(/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/gm)) {
		const start = Number(m[1]);
		const count = m[2] === undefined ? 1 : Number(m[2]);
		if (count > 0) ranges.push([start, start + count - 1]);
	}
	return ranges;
}

/**
 * Keep only phpcs messages that sit on a changed line.
 *
 * @param {Array<{line: number}>} messages phpcs JSON messages.
 * @param {Array<[number, number]>|null} ranges Changed ranges, or null meaning the whole file is new.
 * @returns {Array<{line: number}>}
 */
export function filterToChanged(messages, ranges) {
	if (ranges === null) return messages;
	return messages.filter((m) => ranges.some(([a, b]) => m.line >= a && m.line <= b));
}

/**
 * Strip anything before the JSON report; phpcs prints ruleset DEPRECATED notices on stdout first.
 *
 * @param {string} stdout Raw phpcs stdout.
 * @returns {string} The JSON document, or the input unchanged if no report marker is found.
 */
export function extractJson(stdout) {
	const start = stdout.indexOf('{"totals"');
	return start === -1 ? stdout : stdout.slice(start);
}

function run(cmd, args, cwd, timeout) {
	return spawnSync(cmd, args, { cwd, encoding: "utf8", timeout, maxBuffer: 16 * 1024 * 1024 });
}

function emit(text) {
	process.stdout.write(
		JSON.stringify({ hookSpecificOutput: { hookEventName: "PostToolUse", additionalContext: text } }),
	);
}

function changedRanges(root, rel) {
	const tracked = run("git", ["ls-files", "--error-unmatch", "--", rel], root, 10000);
	if (tracked.status !== 0) return null;
	const diff = run("git", ["diff", "-U0", "HEAD", "--", rel], root, 10000);
	if (diff.status !== 0) throw new Error(`git diff failed: ${diff.stderr}`);
	return parseChangedRanges(diff.stdout);
}

/**
 * Locate phpcs for a checkout. A git worktree has no vendor/ (it is gitignored), so fall back to
 * the main checkout's binary; phpcs then still reads the worktree's phpcs.xml from the run's cwd.
 *
 * @param {string} root Repository root that owns the edited file.
 * @returns {string|null} Path to vendor/bin/phpcs, or null when none is installed.
 */
function findPhpcs(root) {
	const candidates = [root, process.env.CLAUDE_PROJECT_DIR].filter(Boolean).map((r) => path.join(r, "vendor", "bin", "phpcs"));
	return candidates.find((c) => fs.existsSync(c)) ?? null;
}

function main() {
	const input = JSON.parse(fs.readFileSync(0, "utf8"));
	const filePath = input.tool_input?.file_path;
	if (!filePath || !filePath.endsWith(".php")) return;

	const root = projectRootFor(filePath, input);
	const abs = path.resolve(root, filePath);
	const rel = path.relative(root, abs).split(path.sep).join("/");
	if (rel.startsWith("..") || path.isAbsolute(rel) || !fs.existsSync(abs)) return;

	const phpcs = findPhpcs(root);
	if (!phpcs) {
		emit("phpcs check skipped: vendor/bin/phpcs is missing. Run `composer install` to enable the coding-standards check.");
		return;
	}

	const ranges = changedRanges(root, rel);
	if (ranges !== null && ranges.length === 0) return;

	const res = run("php", [phpcs, "--report=json", "-s", rel], root, PHPCS_TIMEOUT_MS);
	if (res.error) {
		emit(`phpcs check skipped: could not run php (${res.error.code || res.error.message}).`);
		return;
	}
	let report;
	try {
		report = JSON.parse(extractJson(res.stdout));
	} catch {
		emit(`phpcs produced unreadable output (exit ${res.status}): ${(res.stderr || res.stdout).slice(0, 300)}`);
		return;
	}
	const file = Object.values(report.files ?? {})[0];
	const messages = file?.messages ?? [];
	if (messages.some((m) => m.source === INTERNAL_ERROR_SOURCE)) {
		emit(`phpcs aborted on ${rel} (a sniff crashed, usually the local PHP version being newer than the pinned WPCS supports), so it was NOT fully checked. CI sniffs on PHP 7.4 (composer.json pins platform 7.4.3); run phpcs under PHP 7.4 for a complete result.`);
		return;
	}
	const hits = filterToChanged(messages, ranges);
	if (hits.length === 0) return;

	const lines = hits
		.slice(0, MAX_REPORTED)
		.map((m) => `  L${m.line} [${m.type.toLowerCase()}] ${m.message} (${m.source})`);
	const more = hits.length > MAX_REPORTED ? `\n  ...and ${hits.length - MAX_REPORTED} more` : "";
	emit(
		`phpcs found ${hits.length} issue(s) on lines you changed in ${rel}. Fix them by hand; do not run phpcbf on a whole legacy file, it reformats untouched code.\n${lines.join("\n")}${more}`,
	);
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
	try {
		main();
	} catch (err) {
		console.error(`phpcs-changed hook failed: ${err.message}`);
		process.exit(1);
	}
}
