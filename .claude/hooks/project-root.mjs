import { spawnSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";

const GIT_TIMEOUT_MS = 10000;

/**
 * Find the repository root that owns a file, so hooks resolve paths correctly in git worktrees.
 *
 * Claude Code keeps CLAUDE_PROJECT_DIR at the original checkout even when a session runs in a
 * worktree, so relative paths computed from it would fall outside the project and every rule
 * would silently not apply. The git toplevel of the file's own directory is right in all cases.
 *
 * @param {string} filePath Absolute or cwd-relative path from the tool input.
 * @param {{cwd?: string}} input Hook input JSON.
 * @param {string} [fallback] Root to use when the file is not inside a git repository.
 * @returns {string} Absolute repository root.
 */
export function projectRootFor(filePath, input = {}, fallback = process.env.CLAUDE_PROJECT_DIR || input.cwd || process.cwd()) {
	let dir = path.dirname(path.resolve(input.cwd || fallback, filePath));
	while (!fs.existsSync(dir)) {
		const parent = path.dirname(dir);
		if (parent === dir) return path.resolve(fallback);
		dir = parent;
	}
	const res = spawnSync("git", ["-C", dir, "rev-parse", "--show-toplevel"], { encoding: "utf8", timeout: GIT_TIMEOUT_MS });
	const top = res.status === 0 ? res.stdout.trim() : "";
	return path.resolve(top || fallback);
}
