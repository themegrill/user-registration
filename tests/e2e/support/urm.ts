import { expect, type Page } from "@playwright/test";
import { STRONG_PASSWORD, uniqueEmail, uniqueUsername } from "./env";
import { ensurePage, restNonce } from "./wp";

/**
 * Put a clean install into the state the product's own onboarding leaves behind.
 *
 * Activating User Registration does not give the site a registration form, a
 * My Account page, or "Anyone can register" — `UR_Install::install()` creates
 * options, tables and roles and records that this is a first run, and stops
 * there. The form and the front-end pages are the setup wizard's job, so until
 * an admin has opened it there is no form to render and no `/my-account/` to log
 * in on. That gap is invisible on a developer's site, where the wizard ran
 * months ago, and total on a disposable CI site, where it never has: three
 * @fresh specs passed locally and failed on every clean run, one of them
 * reporting a 20s timeout on an admin list row that was correctly empty.
 *
 * Opening the wizard is a GET, and the provisioning happens in the handler for
 * it — `UR_Getting_Started::get_wizard_state()` calls `install_initial_pages()`
 * (which sets `users_can_register` and creates the registration, login,
 * lost-password and my-account pages) and `ensure_default_form()`. So one
 * authenticated request is the whole fixture.
 *
 * Going through the product's own bootstrap rather than hand-building a form is
 * deliberate. The default form's field JSON is a product detail that already
 * lives in two places in the plugin; a third copy here would be the one that
 * quietly stops matching what a real install has, and every spec that fills the
 * form would then be testing the fixture.
 *
 * Idempotent by the product's design — both helpers return existing ids when
 * they find them — so specs call it unconditionally rather than probing first.
 */
/**
 * Site state, not session state, so these are cached for the whole run.
 *
 * Unlike the REST nonce these do not expire with a browser context: once the
 * wizard has run it has run, and the default form keeps its id. Re-deriving
 * them per test cost two admin page loads each, which is what put the first CI
 * run of this suite over its per-test ceiling. A worker restart after a failure
 * resets them and they are simply re-derived.
 */
let firstRunDone = false;
let cachedFormId: number | null = null;

export async function ensureFirstRun(page: Page): Promise<void> {
  if (firstRunDone) return;

  // A form already on the site means the wizard has run — on a developer's
  // machine, months ago. Stop before touching anything, because the bootstrap
  // is not read-only: `install_initial_pages()` sets `users_can_register` and
  // clears `user_registration_login_options_prevent_core_login`. Flipping
  // either of those on somebody's real site, unasked, is not a fixture's
  // business, and a suite that edits the site it is measuring is one nobody
  // will point at their own install twice.
  const existing = await formIdFromAdminList(page);
  if (existing) {
    cachedFormId = existing;
    firstRunDone = true;
    return;
  }

  const nonce = await restNonce(page);
  const status = await page.evaluate(async (nonce) => {
    const r = await fetch("/wp-json/user-registration/v1/getting-started", {
      headers: { "X-WP-Nonce": nonce },
      credentials: "same-origin",
    });
    return r.status;
  }, nonce);

  if (status !== 200) {
    throw new Error(`the first-run bootstrap answered HTTP ${status}`);
  }

  firstRunDone = true;
}

/** The id of the first registration form on the site. */
export async function firstFormId(page: Page): Promise<number> {
  if (cachedFormId) return cachedFormId;

  const nonce = await restNonce(page);
  const id = await page.evaluate(async (nonce) => {
    const r = await fetch("/wp-json/wp/v2/user_registration?per_page=1&status=publish", {
      headers: { "X-WP-Nonce": nonce },
      credentials: "same-origin",
    });
    if (r.ok) {
      const j = await r.json();
      if (Array.isArray(j) && j[0]?.id) return j[0].id as number;
    }
    return null;
  }, nonce);
  if (id) return (cachedFormId = id);

  // The CPT is not REST-exposed — `register_post_type` passes no `show_in_rest`
  // — so the fetch above is always the losing branch today. The admin list
  // table is the real lookup, not the fallback it reads as.
  const fromList = await formIdFromAdminList(page);
  if (fromList) return (cachedFormId = fromList);

  // An empty list on a site whose wizard has never run is expected, not a
  // failure. Provision it the way the product does and look once more.
  await ensureFirstRun(page);
  const afterBootstrap = await formIdFromAdminList(page);
  if (afterBootstrap) return (cachedFormId = afterBootstrap);

  throw new Error(
    "no registration form exists on this site, and the first-run bootstrap did not create one",
  );
}

/** Read the first form id off the admin list table, or null when it is empty. */
async function formIdFromAdminList(page: Page): Promise<number | null> {
  await page.goto("/wp-admin/edit.php?post_type=user_registration");
  const row = page.locator("#the-list tr[id^='post-']").first();

  // `count()` rather than letting `getAttribute` wait: "no forms yet" is an
  // answer this function has to be able to give, and waiting the full action
  // timeout for a row that is not coming turns it into a 20s TimeoutError
  // pointing at the locator — which is how this read as a broken selector in
  // CI rather than as an empty site.
  if (!(await row.count())) return null;

  const parsed = Number(((await row.getAttribute("id")) ?? "").replace("post-", ""));
  return parsed || null;
}

/** Publish a page that renders the given form, and return its URL. */
export async function registrationPageFor(page: Page, formId: number) {
  return await ensurePage(page, {
    slug: `qa-registration-${formId}`,
    title: `QA Registration ${formId}`,
    content: `<!-- wp:shortcode -->[user_registration_form id="${formId}"]<!-- /wp:shortcode -->`,
  });
}

export type NewUser = { username: string; email: string; password: string };

/**
 * Fill and submit a registration form on the front end.
 *
 * The form is `form.register` inside `div.ur-frontend-form` — not a form with
 * that class, which is the easy locator to get wrong. Optional fields are only
 * filled when the form actually has them, because the field set is per-form
 * configuration, not a product constant.
 *
 * If the form carries a membership selector the free plan is chosen: a paid
 * plan would divert into a gateway and this helper is for registration, not
 * for payment.
 */
export async function registerOn(page: Page, url: string, user?: Partial<NewUser>): Promise<NewUser> {
  const account: NewUser = {
    username: user?.username ?? uniqueUsername(),
    email: user?.email ?? uniqueEmail(),
    password: user?.password ?? STRONG_PASSWORD,
  };

  await page.goto(url);
  const form = page.locator("div.ur-frontend-form form.register");
  await expect(form).toBeVisible();

  await form.locator("#user_login").fill(account.username);
  await form.locator("#user_email").fill(account.email);
  await form.locator("#user_pass").fill(account.password);

  const confirm = form.locator("#user_confirm_password");
  if (await confirm.count()) await confirm.fill(account.password);
  const confirmEmail = form.locator("#user_confirm_email");
  if (await confirmEmail.count()) await confirmEmail.fill(account.email);

  await selectFreeMembership(form);

  const privacy = form.locator("input[id^='privacy_policy']");
  if (await privacy.count()) await privacy.first().check();

  await form.locator("button[type=submit], input[type=submit]").first().click();
  return account;
}

/**
 * Choose the free plan in a membership field, if one is present.
 *
 * The plan ids are site data, not product data, so they are matched on the
 * rendered price rather than hardcoded — a spec pinned to "membership 71"
 * breaks the first time somebody edits the plans.
 */
async function selectFreeMembership(form: ReturnType<Page["locator"]>) {
  // The membership field is re-rendered by the module's own JS after load, and
  // the rendered control is named `urm_membership` — the server-side markup
  // ships `ur-membership-id`, which is gone by the time a user could click it.
  // Querying the server name finds nothing and silently skips a required field.
  const radios = form.locator("input[name='urm_membership']");
  try {
    await radios.first().waitFor({ state: "attached", timeout: 15_000 });
  } catch {
    return; // this form genuinely has no membership field
  }
  const count = await radios.count();
  if (count === 0) return;

  for (let i = 0; i < count; i++) {
    const radio = radios.nth(i);
    const id = await radio.getAttribute("id");
    const label = form.locator(`label[for="${id}"]`);
    const text = ((await label.count()) ? await label.innerText() : "").toLowerCase();
    if (/\bfree\b/.test(text) || /\$\s*0(\.0+)?\b/.test(text)) {
      await radio.check();
      return;
    }
  }
  // No free plan on this form: take the first so the field validates, and let
  // the caller's assertions decide whether that is acceptable.
  await radios.first().check();
}

/** Log in through the My Account front-end form. */
export async function loginToMyAccount(page: Page, username: string, password: string) {
  await page.goto("/my-account/");
  await page.fill("input[name=username]", username);
  await page.fill("input[name=password]", password);
  await page.locator("button:has-text('Login'), input[type=submit][name=login]").first().click();
  await page.waitForLoadState("domcontentloaded");
}
