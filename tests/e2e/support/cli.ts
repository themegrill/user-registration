/**
 * Minimal wp-cli bridge, for the one thing the REST API cannot reach: the
 * `user_registration` form CPT has no `show_in_rest`, so a spec needing its
 * own isolated form (rather than the shared default one every other spec
 * depends on) has no REST route to create it through.
 *
 * Absence is a SKIP, never a failure: Playground has no shell to run `wp` in
 * at all, and a CI runner with no wp-cli on PATH must not turn this red.
 */
import { execFileSync } from "node:child_process";

let cached: boolean | null = null;

/** Is a `wp` binary reachable on PATH? Used to skip, not to fail. */
export function wpCliAvailable(): boolean {
  if (cached !== null) return cached;
  try {
    execFileSync("wp", ["--version"], { stdio: "ignore", timeout: 5_000 });
    cached = true;
  } catch {
    cached = false;
  }
  return cached;
}

/** Run a `wp` subcommand against the site under test, returning its stdout. */
export function wpCli(args: string[]): string {
  const extra = process.env.TGQA_WP_PATH ? [`--path=${process.env.TGQA_WP_PATH}`] : [];
  return execFileSync("wp", [...args, ...extra], { encoding: "utf8", timeout: 15_000 }).trim();
}
