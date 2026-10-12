import { test, expect } from "@playwright/test";
import { execFileSync } from "node:child_process";
import path from "node:path";

test("feed and public discovery access legacy @fresh", () => {
  const wordpress = process.env.UR_SECURITY_WP_PATH;
  test.skip(!wordpress, "Set UR_SECURITY_WP_PATH to a disposable WordPress install with this branch active.");
  const result = execFileSync("wp", [
    "eval-file",
    path.resolve(__dirname, "../support/feeds-integration.php"),
    "legacy",
    `--path=${wordpress}`,
  ], {
    encoding: "utf8",
    timeout: 60_000,
    env: { ...process.env, UR_SECURITY_DISPOSABLE: "1" },
  });
  expect(result).toContain("36 assertions passed");
});

test("feed and public discovery access per-post @fresh", () => {
  const wordpress = process.env.UR_SECURITY_WP_PATH;
  test.skip(!wordpress, "Set UR_SECURITY_WP_PATH to a disposable WordPress install with this branch active.");
  const result = execFileSync("wp", [
    "eval-file",
    path.resolve(__dirname, "../support/feeds-integration.php"),
    "per-post",
    `--path=${wordpress}`,
  ], {
    encoding: "utf8",
    timeout: 60_000,
    env: { ...process.env, UR_SECURITY_DISPOSABLE: "1" },
  });
  expect(result).toContain("36 assertions passed");
});

test("feed and public discovery access whole-site @fresh", () => {
  const wordpress = process.env.UR_SECURITY_WP_PATH;
  test.skip(!wordpress, "Set UR_SECURITY_WP_PATH to a disposable WordPress install with this branch active.");
  const result = execFileSync("wp", [
    "eval-file",
    path.resolve(__dirname, "../support/feeds-integration.php"),
    "whole-site",
    `--path=${wordpress}`,
  ], {
    encoding: "utf8",
    timeout: 60_000,
    env: { ...process.env, UR_SECURITY_DISPOSABLE: "1" },
  });
  expect(result).toContain("36 assertions passed");
});

test("feed and public discovery access post-type @fresh", () => {
  const wordpress = process.env.UR_SECURITY_WP_PATH;
  test.skip(!wordpress, "Set UR_SECURITY_WP_PATH to a disposable WordPress install with this branch active.");
  const result = execFileSync("wp", [
    "eval-file",
    path.resolve(__dirname, "../support/feeds-integration.php"),
    "post-type",
    `--path=${wordpress}`,
  ], {
    encoding: "utf8",
    timeout: 60_000,
    env: { ...process.env, UR_SECURITY_DISPOSABLE: "1" },
  });
  expect(result).toContain("36 assertions passed");
});

test("feed and public discovery access membership @fresh", () => {
  const wordpress = process.env.UR_SECURITY_WP_PATH;
  test.skip(!wordpress, "Set UR_SECURITY_WP_PATH to a disposable WordPress install with this branch active.");
  const result = execFileSync("wp", [
    "eval-file",
    path.resolve(__dirname, "../support/feeds-integration.php"),
    "membership",
    `--path=${wordpress}`,
  ], {
    encoding: "utf8",
    timeout: 60_000,
    env: { ...process.env, UR_SECURITY_DISPOSABLE: "1" },
  });
  expect(result).toContain("36 assertions passed");
});
