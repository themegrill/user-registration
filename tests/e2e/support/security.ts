import { expect } from "@playwright/test";
import { execFile } from "node:child_process";
import { createHash } from "node:crypto";
import { mkdtemp, readFile, rm } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { promisify } from "node:util";

const run = promisify(execFile);
const root = path.resolve(__dirname, "../../..");

/** Run the actual PHP handler regression in an isolated process, without a site/database. */
export async function runSecurityRegression(name: string, assertions: number, core?: string) {
  const args = [path.join(root, "tests/security", `${name}.php`)];
  if (core) args.push(core);
  const result = await run(process.env.UR_SECURITY_PHP_BINARY ?? "php", args, {
    cwd: root,
    timeout: 30_000,
  });
  expect(result.stderr, "PHP warnings/errors must fail the regression").toBe("");
  expect(result.stdout.trim()).toMatch(new RegExp(`^${assertions} assertions (passed|completed)$`));
}

/** Reuse a local core checkout, or prepare a checksum-pinned parser fixture for CI. */
export async function withWordPressCore(check: (core: string) => Promise<void>) {
  if (process.env.UR_SECURITY_WP_ROOT) {
    await check(process.env.UR_SECURITY_WP_ROOT);
    return;
  }
  const fixture = await mkdtemp(path.join(os.tmpdir(), "ur-security-core-"));
  try {
    const archive = path.join(fixture, "wordpress.tar.gz");
    await run("curl", ["--fail", "--silent", "--show-error", "--location",
      "--connect-timeout", "15", "--max-time", "60",
      "https://wordpress.org/wordpress-6.8.3.tar.gz", "--output", archive], { timeout: 65_000 });
    const digest = createHash("sha256").update(await readFile(archive)).digest("hex");
    expect(digest, "Verify the fixed WordPress fixture before extracting/executing it").toBe(
      "92da34c9960e64d1258652c1ef73c517f7e46ac6dfd2dfc75436d3855af46b0c",
    );
    await run("tar", ["-xzf", archive, "-C", fixture], { timeout: 15_000 });
    await check(path.join(fixture, "wordpress"));
  } finally {
    await rm(fixture, { recursive: true, force: true });
  }
}
