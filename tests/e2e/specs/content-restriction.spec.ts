import { expect, test, type Browser, type BrowserContext, type Page } from "@playwright/test";
import { loginAsAdmin, newVisitor, restNonce } from "../support/wp";
import { BASE_URL, uniqueEmail, uniqueUsername, STRONG_PASSWORD } from "../support/env";

/**
 * Content Restriction has no other Playwright coverage, so this is the first
 * spec for the area. It seeds pages, access rules and a member user directly
 * over the REST API (the admin rule-builder UI is a separate, unasserted
 * surface here) and only drives the browser for the actual front-end
 * visibility check, per the "seed state, click only what is under test" rule.
 */

const stamp = () => Date.now().toString(36);

type Condition = { type: "user_state"; value: "logged-in" | "logged-out" } | { type: "roles"; value: string[] };

type RestPage = { status: number; rendered: string; isProtected: boolean };

/**
 * Everything a test creates, recorded the moment it exists so a failure part
 * way through seeding still gets cleaned up.
 */
class Seed {
  private rules: number[] = [];
  private pages: number[] = [];
  private users: number[] = [];
  private contexts: BrowserContext[] = [];

  constructor(private readonly admin: Page) {}

  async page(title: string, content: string): Promise<number> {
    const id = await adminFetch<{ id: number }>(this.admin, "POST", "/wp-json/wp/v2/pages", { title, content, status: "publish" });
    this.pages.push(id.id);
    return id.id;
  }

  /** access_rule_data in the shape the admin rule-builder saves: a whole-site target carries no 'value'. */
  async rule(
    title: string,
    targetContents: unknown,
    condition: Condition,
    message: string,
    accessControl: "access" | "restrict" = "access",
  ): Promise<number> {
    const json = await adminFetch<{ rule: { id: number } }>(this.admin, "POST", "/wp-json/user-registration/v1/content-access-rules", {
      title,
      access_rule_data: {
        enabled: true,
        target_contents: targetContents,
        logic_map: { type: "group", logic_gate: "AND", conditions: [condition] },
        actions: [
          {
            type: "message",
            label: "Show Message",
            message,
            redirect_url: "",
            access_control: accessControl,
            local_page: "",
            ur_form: "",
            shortcode: { tag: "", args: "" },
          },
        ],
      },
    });
    this.rules.push(json.rule.id);
    return json.rule.id;
  }

  async member(): Promise<string> {
    const username = uniqueUsername("qacrmember");
    const json = await adminFetch<{ id: number }>(this.admin, "POST", "/wp-json/wp/v2/users", {
      username,
      email: uniqueEmail("qacrmember"),
      password: STRONG_PASSWORD,
      roles: ["subscriber"],
    });
    this.users.push(json.id);
    return username;
  }

  track(context: BrowserContext): BrowserContext {
    this.contexts.push(context);
    return context;
  }

  /** Rules go first, so a leftover Whole Site rule can never lock other specs out of the site. */
  async cleanUp(): Promise<void> {
    await Promise.allSettled(this.contexts.map((context) => context.close()));
    const deletions = [
      ...this.rules.map((id) => `/wp-json/user-registration/v1/content-access-rules/${id}?force=true`),
      ...this.pages.map((id) => `/wp-json/wp/v2/pages/${id}?force=true`),
      ...this.users.map((id) => `/wp-json/wp/v2/users/${id}?force=true&reassign=1`),
    ];
    const failures: string[] = [];
    for (const path of deletions) {
      await adminFetch(this.admin, "DELETE", path).catch((error: Error) => failures.push(error.message));
    }
    if (failures.length) throw new Error(`cleanup left data behind:\n${failures.join("\n")}`);
  }
}

async function adminFetch<T = unknown>(admin: Page, method: string, path: string, body?: unknown): Promise<T> {
  const nonce = await restNonce(admin);
  return await admin.evaluate(
    async ({ method, path, body, nonce }) => {
      const res = await fetch(path, {
        method,
        headers: { "Content-Type": "application/json", "X-WP-Nonce": nonce },
        credentials: "same-origin",
        body: body === undefined ? undefined : JSON.stringify(body),
      });
      const text = await res.text();
      if (!res.ok) throw new Error(`${method} ${path} failed: ${res.status} ${text.slice(0, 200)}`);
      return (text ? JSON.parse(text) : null) as T;
    },
    { method, path, body, nonce },
  );
}

/** Runs a test body with a Seed, then always cleans up without hiding the body's own failure. */
async function withSeed(admin: Page, body: (seed: Seed) => Promise<void>): Promise<void> {
  const seed = new Seed(admin);
  let bodyError: unknown = null;
  try {
    await body(seed);
  } catch (error) {
    bodyError = error;
  }
  try {
    await seed.cleanUp();
  } catch (cleanupError) {
    if (!bodyError) throw cleanupError;
  }
  if (bodyError) throw bodyError;
}

/**
 * loginAsAdmin() asserts #wpadminbar, which a subscriber created over the
 * REST API is not guaranteed to see on the front end — that is a separate
 * question from the one this spec asks. Log in and wait for the redirect
 * away from wp-login.php instead.
 */
async function loginAsMember(browser: Browser, seed: Seed, user: string): Promise<BrowserContext> {
  const context = seed.track(await browser.newContext({ baseURL: BASE_URL, ignoreHTTPSErrors: true }));
  const page = await context.newPage();
  await page.goto("/wp-login.php");
  await page.fill("#user_login", user);
  await page.fill("#user_pass", STRONG_PASSWORD);
  await page.click("#wp-submit");
  await page.waitForURL((url) => !url.pathname.includes("wp-login.php"));
  await page.close();
  return context;
}

async function bodyOf(context: BrowserContext, pageId: number): Promise<string> {
  const page = await context.newPage();
  await page.goto(`/?page_id=${pageId}`, { waitUntil: "domcontentloaded" });
  const text = await page.locator("body").innerText();
  await page.close();
  return text;
}

/**
 * The page as the logged-in member gets it from the core REST API, which
 * enforces the same rules through urcr_is_content_access_granted(). restNonce()
 * reads the nonce from a screen a subscriber cannot open, so use core's
 * rest-nonce action instead.
 */
async function restPageAsMember(context: BrowserContext, pageId: number): Promise<RestPage> {
  const page = await context.newPage();
  await page.goto("/");
  const result = await page.evaluate(async (pageId) => {
    const nonceRes = await fetch("/wp-admin/admin-ajax.php?action=rest-nonce", { credentials: "same-origin" });
    if (!nonceRes.ok) throw new Error(`rest-nonce failed: ${nonceRes.status}`);
    const res = await fetch(`/wp-json/wp/v2/pages/${pageId}`, {
      headers: { "X-WP-Nonce": await nonceRes.text() },
      credentials: "same-origin",
    });
    const json = await res.json();
    return {
      status: res.status,
      rendered: (json?.content?.rendered ?? "") as string,
      isProtected: json?.content?.protected === true,
    };
  }, pageId);
  await page.close();
  return result;
}

test.describe("content restriction access rules @fresh", () => {
  /**
   * @area    content-restriction
   * @tier    fresh
   * @guards  1412
   * @source  verify-fix 2026-09-23
   * @why     A rule targeting specific content is resolved separately from a
   *          Whole Site rule, and a restriction from either wins, so a Whole
   *          Site grant for logged-in users must not let a member past a
   *          guests-only page rule. This is the behaviour issue 1412's customer
   *          found surprising; it is deliberate, and the render path and
   *          urcr_is_content_access_granted() must agree on it. Does not assert
   *          the rule-builder UI.
   */
  test("a guests-only page rule keeps members out even when a Whole Site rule lets them in @fresh @content-restriction", async ({
    page,
    browser,
  }) => {
    await loginAsAdmin(page);
    await withSeed(page, async (seed) => {
      const secret = `QA CR secret ${stamp()}`;
      const wholeSiteMarker = `QA CR whole-site marker ${stamp()}`;
      const pageRuleMarker = `QA CR guests-only marker ${stamp()}`;

      const targetPageId = await seed.page(`QA CR guests page ${stamp()}`, secret);
      const otherPageId = await seed.page(`QA CR other page ${stamp()}`, secret);
      await seed.rule("QA CR whole site logged-in", [{ type: "whole_site" }], { type: "user_state", value: "logged-in" }, wholeSiteMarker);
      await seed.rule("QA CR page guests only", [{ type: "wp_pages", value: [String(targetPageId)] }], { type: "user_state", value: "logged-out" }, pageRuleMarker);
      const username = await seed.member();

      const guest = seed.track(await newVisitor(browser));
      expect(await bodyOf(guest, targetPageId)).toContain(secret);
      expect(await bodyOf(guest, otherPageId)).toContain(wholeSiteMarker);

      const member = await loginAsMember(browser, seed, username);
      const memberOnTarget = await bodyOf(member, targetPageId);
      expect(memberOnTarget).toContain(pageRuleMarker);
      expect(memberOnTarget).not.toContain(secret);
      const rest = await restPageAsMember(member, targetPageId);
      expect(rest.status).toBe(200);
      expect(rest.isProtected).toBe(true);
      expect(rest.rendered).not.toContain(secret);
      expect(await bodyOf(member, otherPageId)).toContain(secret);
    });
  });

  /**
   * @area    content-restriction
   * @tier    fresh
   * @guards  1412
   * @source  verify-fix 2026-09-23
   * @why     Letting a Whole Site grant override a page rule (the first attempt
   *          at 1412) exposed a page restricted to one role to every logged-in
   *          user. Guards that leak on both the rendered page and the REST
   *          API. Does not cover membership conditions, which resolve through
   *          the same code path.
   */
  test("a Whole Site rule does not unlock a page restricted to another role @fresh @content-restriction", async ({
    page,
    browser,
  }) => {
    await loginAsAdmin(page);
    await withSeed(page, async (seed) => {
      const secret = `QA CR editors secret ${stamp()}`;
      const pageRuleMarker = `QA CR editors-only marker ${stamp()}`;

      const editorsPageId = await seed.page(`QA CR editors page ${stamp()}`, secret);
      await seed.rule("QA CR whole site logged-in", [{ type: "whole_site" }], { type: "user_state", value: "logged-in" }, `QA CR whole-site marker ${stamp()}`);
      await seed.rule("QA CR page editors only", [{ type: "wp_pages", value: [String(editorsPageId)] }], { type: "roles", value: ["editor"] }, pageRuleMarker);
      const username = await seed.member();

      const member = await loginAsMember(browser, seed, username);
      const memberOnPage = await bodyOf(member, editorsPageId);
      expect(memberOnPage).toContain(pageRuleMarker);
      expect(memberOnPage).not.toContain(secret);
      const rest = await restPageAsMember(member, editorsPageId);
      expect(rest.status).toBe(200);
      expect(rest.isProtected).toBe(true);
      expect(rest.rendered).not.toContain(secret);
    });
  });

  /**
   * @area    content-restriction
   * @tier    fresh
   * @guards  1412
   * @source  verify-fix 2026-09-23
   * @why     The supported way to open one page to guests without locking
   *          members out, and the one the rule editor's hint points to: a
   *          second rule for the same page. Within the page pass a grant wins
   *          over a restriction. Does not assert the hint text itself.
   */
  test("a second rule for the same page lets members in alongside guests @fresh @content-restriction", async ({
    page,
    browser,
  }) => {
    await loginAsAdmin(page);
    await withSeed(page, async (seed) => {
      const secret = `QA CR shared secret ${stamp()}`;

      const sharedPageId = await seed.page(`QA CR shared page ${stamp()}`, secret);
      await seed.rule("QA CR whole site logged-in", [{ type: "whole_site" }], { type: "user_state", value: "logged-in" }, `QA CR whole-site marker ${stamp()}`);
      await seed.rule("QA CR page guests", [{ type: "wp_pages", value: [String(sharedPageId)] }], { type: "user_state", value: "logged-out" }, `QA CR guests marker ${stamp()}`);
      await seed.rule("QA CR page members", [{ type: "wp_pages", value: [String(sharedPageId)] }], { type: "user_state", value: "logged-in" }, `QA CR members marker ${stamp()}`);
      const username = await seed.member();

      const guest = seed.track(await newVisitor(browser));
      expect(await bodyOf(guest, sharedPageId)).toContain(secret);

      const member = await loginAsMember(browser, seed, username);
      expect(await bodyOf(member, sharedPageId)).toContain(secret);
      const rest = await restPageAsMember(member, sharedPageId);
      expect(rest.status).toBe(200);
      expect(rest.isProtected).toBe(false);
      expect(rest.rendered).toContain(secret);
    });
  });

  /**
   * @area    content-restriction
   * @tier    fresh
   * @guards  1412
   * @source  verify-fix 2026-09-23
   * @why     Backs the rule editor's hint for a Restrict rule on a page: a
   *          Whole Site grant does not lift it, but another rule for the same
   *          page that grants access does. Does not assert the hint text.
   */
  test("a Restrict page rule holds against a Whole Site grant until another rule for the page allows access @fresh @content-restriction", async ({
    page,
    browser,
  }) => {
    await loginAsAdmin(page);
    await withSeed(page, async (seed) => {
      const secret = `QA CR restricted secret ${stamp()}`;
      const restrictMarker = `QA CR restrict marker ${stamp()}`;

      const restrictedPageId = await seed.page(`QA CR restricted page ${stamp()}`, secret);
      await seed.rule("QA CR whole site logged-in", [{ type: "whole_site" }], { type: "user_state", value: "logged-in" }, `QA CR whole-site marker ${stamp()}`);
      await seed.rule("QA CR page restrict logged-in", [{ type: "wp_pages", value: [String(restrictedPageId)] }], { type: "user_state", value: "logged-in" }, restrictMarker, "restrict");
      const username = await seed.member();

      const member = await loginAsMember(browser, seed, username);
      const blocked = await bodyOf(member, restrictedPageId);
      expect(blocked).toContain(restrictMarker);
      expect(blocked).not.toContain(secret);
      expect((await restPageAsMember(member, restrictedPageId)).isProtected).toBe(true);

      await seed.rule("QA CR page subscribers", [{ type: "wp_pages", value: [String(restrictedPageId)] }], { type: "roles", value: ["subscriber"] }, `QA CR subscribers marker ${stamp()}`);
      expect(await bodyOf(member, restrictedPageId)).toContain(secret);
      const rest = await restPageAsMember(member, restrictedPageId);
      expect(rest.status).toBe(200);
      expect(rest.isProtected).toBe(false);
      expect(rest.rendered).toContain(secret);
    });
  });
});
