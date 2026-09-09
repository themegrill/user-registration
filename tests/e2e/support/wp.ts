import { expect, type Browser, type BrowserContext, type Page } from "@playwright/test";
import { ADMIN_PASS, ADMIN_USER, BASE_URL } from "./env";

/**
 * Log in to wp-admin.
 *
 * WordPress periodically interrupts the first admin login with the
 * "confirm your administration email" screen. It is not an error and it does
 * not appear every time, so it cannot be asserted on — it just has to be
 * stepped past, or every spec becomes flaky on a schedule nobody controls.
 *
 * The wp-admin check first is not an optimisation. Playground — the engine CI
 * boots — runs with `--login`, which authenticates a browser context as admin
 * on its first request to the site. Driving wp-login.php on top of that races
 * the auto-login's own redirect, and the loser is `#wpadminbar`: one CI run
 * failed here with "element(s) not found" and passed on retry, which is what
 * that race looks like from the outside.
 */
export async function loginAsAdmin(page: Page, user = ADMIN_USER, pass = ADMIN_PASS) {
  await page.goto("/wp-admin/");
  if (await page.locator("#wpadminbar").count()) return;

  await page.goto("/wp-login.php");
  await page.fill("#user_login", user);
  await page.fill("#user_pass", pass);
  await page.click("#wp-submit");
  await page.waitForLoadState("domcontentloaded");

  if (page.url().includes("confirm_admin_email")) {
    const later = page.locator("a.admin-email__later, a:has-text('Remind me later')");
    if (await later.count()) await later.first().click();
    else await page.goto("/wp-admin/");
    await page.waitForLoadState("domcontentloaded");
  }
  // 45s, not the 15s `expect` default. This is the first thing every spec does,
  // and on the first test of a run it lands on a Playground that has only just
  // settled — WASM PHP still cold, opcache empty. That is where the one
  // remaining CI flake was: attempt 0 of the run's first test failed here with
  // "#wpadminbar element(s) not found", and the retry passed on a warm site.
  // A cold start is not a regression and should not cost a retry to discover.
  await expect(page.locator("#wpadminbar")).toBeVisible({ timeout: 45_000 });
}

/**
 * A browser context that really is a logged-out visitor.
 *
 * `browser.newContext()` is not one. Playground boots with `--login`, and that
 * flag logs EVERY new context in as admin on its first request — so a spec that
 * opens a "visitor" context, registers an account through the front end and
 * then expects the My Account login form is handed the admin dashboard instead.
 * The failure surfaces as `input[name=username]` never appearing, which reads
 * like a broken login form rather than like a session that was never anonymous.
 * Worse than the red: `settings-security.spec.ts` asserts that a subscriber
 * keeps the admin bar, and an admin session satisfies that for the wrong reason.
 *
 * So the auto-login is allowed to happen once and then undone. The WordPress
 * auth cookies are dropped and Playground's own
 * `playground_auto_login_already_happened` marker is kept — keeping the marker
 * is the whole trick, because clearing every cookie just invites the next
 * request to log the context straight back in.
 *
 * On a normal WordPress there is neither a marker nor an auth cookie to drop,
 * so this is an extra page load and nothing else. `baseURL` is passed
 * explicitly because a context built from the `browser` fixture does not
 * reliably inherit the one in `use`.
 */
export async function newVisitor(browser: Browser): Promise<BrowserContext> {
  const context = await browser.newContext({
    baseURL: BASE_URL,
    ignoreHTTPSErrors: true,
  });

  const page = await context.newPage();
  await page.goto("/");
  const keep = (await context.cookies()).filter(
    (cookie) => !cookie.name.startsWith("wordpress"),
  );
  await context.clearCookies();
  await context.addCookies(keep);
  await page.close();

  return context;
}

/** Open a User Registration admin screen by its `page=` slug. */
export async function gotoAdminPage(page: Page, slug: string, extra = "") {
  await page.goto(`/wp-admin/admin.php?page=${slug}${extra}`);
  await page.waitForLoadState("domcontentloaded");
}

/**
 * Read an option through the REST API using the logged-in admin's own nonce.
 *
 * Asserting on the stored option rather than on a success notice is the whole
 * point: the notice can appear while the write silently failed, which is
 * exactly the class of bug this product's history is full of.
 */
export async function restNonce(page: Page): Promise<string> {
  const cached = nonceByContext.get(page.context());
  if (cached) return cached;

  await page.goto("/wp-admin/options-general.php");
  const nonce = await page.evaluate(
    () => (window as any).wpApiSettings?.nonce ?? null,
  );
  if (!nonce) throw new Error("could not read the REST nonce from wp-admin");

  nonceByContext.set(page.context(), nonce);
  return nonce;
}

/**
 * One nonce per browser context, because reading it costs a full admin page
 * load and four helpers want it — `ensureFirstRun`, `firstFormId`,
 * `ensurePage` and `deleteUserByEmail`. Under Playground's WASM PHP those
 * three redundant loads are seconds each, and the first CI run of this suite
 * spent them in every test: three login specs passed only on retry and the
 * ajax spec blew the 45s per-test ceiling outright, on a runner slower than
 * any developer's machine.
 *
 * Keyed on the CONTEXT and not memoised for the whole run, which is the part
 * that matters for correctness. A WordPress nonce is tied to the user's session
 * token, every test gets a fresh context, and a fresh context means a fresh
 * login — so a nonce minted in one test is not valid in the next. A run-wide
 * cache would hand out stale nonces and fail as "Cookie check failed".
 */
const nonceByContext = new WeakMap<BrowserContext, string>();

/** Create or update a page by slug, returning its permalink. */
export async function ensurePage(
  page: Page,
  { slug, title, content }: { slug: string; title: string; content: string },
): Promise<string> {
  const nonce = await restNonce(page);
  return await page.evaluate(
    async ({ slug, title, content, nonce }) => {
      const headers = { "Content-Type": "application/json", "X-WP-Nonce": nonce };
      const found = await fetch(
        `/wp-json/wp/v2/pages?slug=${encodeURIComponent(slug)}&status=publish,draft`,
        { headers, credentials: "same-origin" },
      ).then((r) => r.json());
      const body = JSON.stringify({ title, slug, status: "publish", content });
      const res = await fetch(
        found?.[0]?.id ? `/wp-json/wp/v2/pages/${found[0].id}` : "/wp-json/wp/v2/pages",
        { method: "POST", headers, credentials: "same-origin", body },
      );
      const json = await res.json();
      if (!res.ok) throw new Error(`page write failed: ${res.status} ${JSON.stringify(json).slice(0, 200)}`);
      return json.link as string;
    },
    { slug, title, content, nonce },
  );
}

/**
 * Delete a user the suite created, by email, using the admin's REST nonce.
 *
 * Every registration-based spec creates a real account on the site under test.
 * Without this the site accumulates one user per test per run, and — because
 * membership subscription rows outlive the user they belong to (see
 * knowledge.md, "Observed while porting the suite") — the plans those users
 * joined slowly become undeletable. Cleaning up is not optional here.
 *
 * Matching is on the exact email, which is generated on the `@example.test`
 * domain, so this can never select an account a person actually uses.
 * Failures are swallowed: teardown must never turn a passing test red.
 */
export async function deleteUserByEmail(page: Page, email: string): Promise<void> {
  if (!email.endsWith("@example.test")) return;
  try {
    const nonce = await restNonce(page);
    await page.evaluate(
      async ({ email, nonce }) => {
        const headers = { "X-WP-Nonce": nonce };
        const found = await fetch(
          `/wp-json/wp/v2/users?search=${encodeURIComponent(email)}&context=edit&per_page=20`,
          { headers, credentials: "same-origin" },
        ).then((r) => (r.ok ? r.json() : []));
        for (const u of Array.isArray(found) ? found : []) {
          if (u?.email !== email) continue;
          await fetch(`/wp-json/wp/v2/users/${u.id}?force=true&reassign=false`, {
            method: "DELETE",
            headers,
            credentials: "same-origin",
          });
        }
      },
      { email, nonce },
    );
  } catch {
    /* teardown is best-effort */
  }
}
