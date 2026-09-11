/**
 * Values the claudegrill runner exports before invoking Playwright.
 *
 * `run-suite.mjs` always sets the generic `TGQA_*` names, resolving them from
 * `.themegrill-qa/.env.local`, the process environment or CI secrets, so specs
 * never read a credential file themselves and never hardcode a site.
 */
export const BASE_URL = process.env.TGQA_BASE_URL ?? "http://localhost:8888";
export const ADMIN_USER = process.env.TGQA_ADMIN_USER ?? "admin";
export const ADMIN_PASS = process.env.TGQA_ADMIN_PASS ?? "password";

/** Unique-per-run identifiers, so reruns never collide on an existing user. */
const stamp = () => `${Date.now().toString(36)}${Math.floor(Math.random() * 1e4)}`;
export const uniqueUsername = (prefix = "qa") => `${prefix}${stamp()}`;
export const uniqueEmail = (prefix = "qa") => `${prefix}${stamp()}@example.test`;
export const STRONG_PASSWORD = "QaPassw0rd!2026";
