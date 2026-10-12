import { test, expect } from "@playwright/test";
import { execFileSync } from "node:child_process";
import path from "node:path";

test("member names and failed profile update @fresh", () => {
  const wordpress = process.env.UR_SECURITY_WP_PATH;
  test.skip(!wordpress, "Set UR_SECURITY_WP_PATH to a disposable WordPress install with this branch active.");
  const result = execFileSync("wp", [
    "eval-file",
    path.resolve(__dirname, "../support/member-names-integration.php"),
    `--path=${wordpress}`,
  ], {
    encoding: "utf8",
    timeout: 60_000,
    env: { ...process.env, UR_SECURITY_DISPOSABLE: "1" },
  });
  expect(result).toContain("9 assertions passed");
});
