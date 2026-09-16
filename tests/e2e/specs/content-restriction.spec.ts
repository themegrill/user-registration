import { expect, test, type Page } from "@playwright/test";
import { loginAsAdmin, newVisitor, restNonce } from "../support/wp";
import { uniqueEmail, uniqueUsername, STRONG_PASSWORD } from "../support/env";

/**
 * Content Restriction has no Playwright coverage yet, so this is the first
 * spec for the area. It seeds pages, access rules and a member user directly
 * over the REST API (the admin rule-builder UI is a separate, unasserted
 * surface here) and only drives the browser for the actual front-end
 * visibility check, per the "seed state, click only what is under test" rule.
 */

const stamp = () => Date.now().toString(36);

async function createPage(page: Page, title: string, content: string): Promise<number> {
  const nonce = await restNonce(page);
  return await page.evaluate(
    async ({ title, content, nonce }) => {
      const res = await fetch("/wp-json/wp/v2/pages", {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-WP-Nonce": nonce },
        credentials: "same-origin",
        body: JSON.stringify({ title, content, status: "publish" }),
      });
      const json = await res.json();
      if (!res.ok) throw new Error(`page create failed: ${res.status} ${JSON.stringify(json).slice(0, 200)}`);
      return json.id as number;
    },
    { title, content, nonce },
  );
}

async function deletePage(page: Page, id: number): Promise<void> {
  const nonce = await restNonce(page);
  await page.evaluate(
    async ({ id, nonce }) => {
      await fetch(`/wp-json/wp/v2/pages/${id}?force=true`, {
        method: "DELETE",
        headers: { "X-WP-Nonce": nonce },
        credentials: "same-origin",
      });
    },
    { id, nonce },
  ).catch(() => {});
}

/** access_rule_data shape matches the admin rule-builder's own serializer. */
async function createAccessRule(
  page: Page,
  title: string,
  targetContents: unknown,
  userState: "logged-in" | "logged-out",
  message: string,
): Promise<number> {
  const nonce = await restNonce(page);
  const access_rule_data = {
    enabled: true,
    target_contents: targetContents,
    logic_map: {
      type: "group",
      logic_gate: "AND",
      conditions: [{ type: "user_state", value: userState }],
    },
    actions: [
      {
        type: "message",
        label: "Show Message",
        message,
        redirect_url: "",
        access_control: "access",
        local_page: "",
        ur_form: "",
        shortcode: { tag: "", args: "" },
      },
    ],
  };
  return await page.evaluate(
    async ({ title, access_rule_data, nonce }) => {
      const res = await fetch("/wp-json/user-registration/v1/content-access-rules", {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-WP-Nonce": nonce },
        credentials: "same-origin",
        body: JSON.stringify({ title, access_rule_data }),
      });
      const json = await res.json();
      if (!res.ok) throw new Error(`rule create failed: ${res.status} ${JSON.stringify(json).slice(0, 300)}`);
      return json.rule.id as number;
    },
    { title, access_rule_data, nonce },
  );
}

async function deleteAccessRule(page: Page, id: number): Promise<void> {
  const nonce = await restNonce(page);
  await page.evaluate(
    async ({ id, nonce }) => {
      await fetch(`/wp-json/user-registration/v1/content-access-rules/${id}?force=true`, {
        method: "DELETE",
        headers: { "X-WP-Nonce": nonce },
        credentials: "same-origin",
      });
    },
    { id, nonce },
  ).catch(() => {});
}

async function createMember(page: Page, username: string, email: string): Promise<number> {
  const nonce = await restNonce(page);
  return await page.evaluate(
    async ({ username, email, password, nonce }) => {
      const res = await fetch("/wp-json/wp/v2/users", {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-WP-Nonce": nonce },
        credentials: "same-origin",
        body: JSON.stringify({ username, email, password, roles: ["subscriber"] }),
      });
      const json = await res.json();
      if (!res.ok) throw new Error(`user create failed: ${res.status} ${JSON.stringify(json).slice(0, 200)}`);
      return json.id as number;
    },
    { username, email, password: STRONG_PASSWORD, nonce },
  );
}

/**
 * loginAsAdmin() asserts #wpadminbar, which a subscriber created over the
 * REST API is not guaranteed to see on the front end — that is a separate
 * question from the one this spec asks. Log in and wait for the redirect
 * away from wp-login.php instead.
 */
async function loginAsMember(page: Page, user: string, pass: string): Promise<void> {
  await page.goto("/wp-login.php");
  await page.fill("#user_login", user);
  await page.fill("#user_pass", pass);
  await page.click("#wp-submit");
  await page.waitForURL((url) => !url.pathname.includes("wp-login.php"));
}

async function deleteUser(page: Page, id: number): Promise<void> {
  const nonce = await restNonce(page);
  await page.evaluate(
    async ({ id, nonce }) => {
      await fetch(`/wp-json/wp/v2/users/${id}?force=true&reassign=1`, {
        method: "DELETE",
        headers: { "X-WP-Nonce": nonce },
        credentials: "same-origin",
      });
    },
    { id, nonce },
  ).catch(() => {});
}

test.describe("content restriction access rules @fresh", () => {
  /**
   * @area    content-restriction
   * @tier    fresh
   * @guards  1412
   * @source  verify-fix 2026-09-16
   * @why     urcr_is_target_post() required a target's 'value' to be
   *          non-empty, which a migrated or auto-created membership rule's
   *          whole-site target never has, so a page-specific rule could
   *          outrank a whole-site grant for that one page. Uses that
   *          value-less shape deliberately: a manually-built whole-site rule
   *          sets value:'whole_site' and never hit this bug. Does not assert
   *          the rule-builder UI itself, or 3+ overlapping rules.
   */
  test("a whole-site rule still grants a member access to a page a guest-only rule also covers @fresh @content-restriction", async ({
    page,
    browser,
  }) => {
    await loginAsAdmin(page);

    const secret = `QA CR secret ${stamp()}`;
    const wholeSiteMarker = `QA CR whole-site marker ${stamp()}`;
    const pageRuleMarker = `QA CR page-rule marker ${stamp()}`;

    const targetPageId = await createPage(page, `QA CR target page ${stamp()}`, secret);
    const otherPageId = await createPage(page, `QA CR other page ${stamp()}`, secret);

    const wholeSiteRuleId = await createAccessRule(
      page,
      "QA CR whole site logged-in only",
      [{ type: "whole_site" }],
      "logged-in",
      wholeSiteMarker,
    );
    const pageRuleId = await createAccessRule(
      page,
      "QA CR target page guests allowed",
      [{ type: "wp_pages", value: [String(targetPageId)] }],
      "logged-out",
      pageRuleMarker,
    );

    const username = uniqueUsername("qacrmember");
    const email = uniqueEmail("qacrmember");
    const memberId = await createMember(page, username, email);

    try {
      // A guest is explicitly allowed on the target page by the page rule...
      const guestCtx = await newVisitor(browser);
      const guestPage = await guestCtx.newPage();
      await guestPage.goto(`/?page_id=${targetPageId}`, { waitUntil: "domcontentloaded" });
      await expect(guestPage.locator("body")).toContainText(secret);
      // ...but the whole-site rule still restricts every other page for them.
      await guestPage.goto(`/?page_id=${otherPageId}`, { waitUntil: "domcontentloaded" });
      await expect(guestPage.locator("body")).toContainText(wholeSiteMarker);
      await guestCtx.close();

      // The regression: a logged-in member satisfies the whole-site grant, so
      // the page rule's guest-only restriction must not apply to them here.
      const memberCtx = await browser.newContext({ ignoreHTTPSErrors: true });
      const memberPage = await memberCtx.newPage();
      await loginAsMember(memberPage, username, STRONG_PASSWORD);
      await memberPage.goto(`/?page_id=${targetPageId}`, { waitUntil: "domcontentloaded" });
      await expect(memberPage.locator("body")).toContainText(secret);
      await expect(memberPage.locator("body")).not.toContainText(pageRuleMarker);
      // And the whole-site rule still grants them every other page too.
      await memberPage.goto(`/?page_id=${otherPageId}`, { waitUntil: "domcontentloaded" });
      await expect(memberPage.locator("body")).toContainText(secret);
      await memberCtx.close();
    } finally {
      await deleteAccessRule(page, wholeSiteRuleId);
      await deleteAccessRule(page, pageRuleId);
      await deletePage(page, targetPageId);
      await deletePage(page, otherPageId);
      await deleteUser(page, memberId);
    }
  });
});
