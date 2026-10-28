/**
 * Values the claudegrill runner exports before invoking Playwright.
 *
 * `run-suite.mjs` always sets the generic `TGQA_*` names, resolving them from
 * `.themegrill-qa/.env.local`, the process environment or CI secrets, so specs
 * never read a credential file themselves and never hardcode a site.
 */
const DEFAULT_BASE_URL = "http://localhost:8888";

/** The first of these variables that is set and not empty. */
const fromEnv = (...names: string[]): string | undefined =>
  names.map((name) => process.env[name]).find((value) => value !== undefined && value !== "");

// The generic TGQA_* names come first: they are what the runner exports. The
// USER_REGISTRATION_* names are what `.themegrill-qa/.env.local` holds, and a run
// that skips the runner (`pnpm test:e2e`) has nothing to map one onto the other.
// Reading only the generic names meant that run reached the right site with the
// `admin`/`password` defaults, and failed 45 seconds later as a `#wpadminbar`
// timeout that never mentioned the file (#1504, #1506).
export const BASE_URL = fromEnv("TGQA_BASE_URL", "USER_REGISTRATION_BASE_URL") ?? DEFAULT_BASE_URL;
export const ADMIN_USER = fromEnv("TGQA_ADMIN_USER", "USER_REGISTRATION_ADMIN_USER") ?? "admin";
export const ADMIN_PASS = fromEnv("TGQA_ADMIN_PASS", "USER_REGISTRATION_ADMIN_PASS") ?? "password";

/**
 * A site was configured but no password was, so the login is using a guess. The
 * guess is right on a Playground or wp-env site and wrong on almost any other.
 */
export const GUESSING_CREDENTIALS =
  BASE_URL !== DEFAULT_BASE_URL && !fromEnv("TGQA_ADMIN_PASS", "USER_REGISTRATION_ADMIN_PASS");

/** Unique-per-run identifiers, so reruns never collide on an existing user. */
const stamp = () => `${Date.now().toString(36)}${Math.floor(Math.random() * 1e4)}`;
export const uniqueUsername = (prefix = "qa") => `${prefix}${stamp()}`;
export const uniqueEmail = (prefix = "qa") => `${prefix}${stamp()}@example.test`;
export const STRONG_PASSWORD = "QaPassw0rd!2026";
