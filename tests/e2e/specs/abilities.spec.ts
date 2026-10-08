import { test, expect } from "@playwright/test";
import { execFileSync } from "node:child_process";
import path from "node:path";

test("read-only abilities permissions, data and MCP integration @fresh", () => {
  const wordpress = process.env.UR_ABILITIES_WP_PATH;
  test.skip(!wordpress, "Set UR_ABILITIES_WP_PATH to a disposable WordPress 6.9+ install with this plugin and MCP Adapter active.");
  const result = execFileSync("wp", [
    "eval-file",
    path.resolve(__dirname, "../support/abilities-integration.php"),
    `--path=${wordpress}`,
  ], {
    encoding: "utf8",
    timeout: 60_000,
    env: { ...process.env, UR_ABILITIES_DISPOSABLE: "1" },
  });
  expect(result).toMatch(/\d+ abilities integration assertions passed/);
});
