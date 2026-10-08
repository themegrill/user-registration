import { expect, test } from "@playwright/test";
import { ensureFirstRun, firstFormId, loginToMyAccount, registerOn, registrationPageFor } from "../support/urm";
import { deleteUserByEmail, loginAsAdmin, newVisitor } from "../support/wp";

/**
 * Ported from UR-Automation `05__setting_login_options` — "Login with Username
 * and email both is selected and login with Username" (tag ci_cd), plus its
 * email sibling.
 *
 * The Robot original sets Login Methods first. That control is not on any
 * settings tab — it belongs to the Login Forms builder — and its default is
 * `default`, meaning "Username or Email". So the shipped configuration is
 * already the state the gate test wants, and setting it would assert on the
 * fixture rather than on the product. If a future spec needs the `username`- or
 * `email`-only modes, it has to drive the login form builder; that is a
 * different test and it is not written yet.
 */
test.describe("login methods @fresh", () => {
  test("a registered user can log in with their username @fresh @login-forms", async ({ page, browser }) => {
    await loginAsAdmin(page);
    await ensureFirstRun(page);
    const url = await registrationPageFor(page, await firstFormId(page));

    const visitor = await newVisitor(browser);
    const user = await visitor.newPage();
    const account = await registerOn(user, url);

    await loginToMyAccount(user, account.username, account.password);
    await expect(user.locator(".user-registration-MyAccount-navigation")).toBeVisible();
    await deleteUserByEmail(page, account.email);
    await visitor.close();
  });

  test("the same user can log in with their email address @fresh @login-forms", async ({ page, browser }) => {
    await loginAsAdmin(page);
    await ensureFirstRun(page);
    const url = await registrationPageFor(page, await firstFormId(page));

    const visitor = await newVisitor(browser);
    const user = await visitor.newPage();
    const account = await registerOn(user, url);

    await loginToMyAccount(user, account.email, account.password);
    await expect(user.locator(".user-registration-MyAccount-navigation")).toBeVisible();
    await deleteUserByEmail(page, account.email);
    await visitor.close();
  });

  test("a wrong password is rejected @fresh @login-forms", async ({ page, browser }) => {
    await loginAsAdmin(page);
    await ensureFirstRun(page);
    const url = await registrationPageFor(page, await firstFormId(page));

    const visitor = await newVisitor(browser);
    const user = await visitor.newPage();
    const account = await registerOn(user, url);

    await loginToMyAccount(user, account.username, "definitely-not-the-password");
    // The negative matters as much as the positive: a login form that accepts
    // anything would pass both tests above.
    await expect(user.locator(".user-registration-MyAccount-navigation")).toHaveCount(0);
    await expect(user.locator("body")).toContainText(/incorrect|invalid|error|not registered/i);
    await deleteUserByEmail(page, account.email);
    await visitor.close();
  });

  /**
   * @area    login-forms
   * @tier    fresh
   * @guards  #1725
   * @source  verify-fix 2026-10-05
   * @why     The login and lost-password inputs carried no autocomplete token, so
   *          password managers could not tell a username field from a password
   *          field (WCAG 1.3.5). Asserts the three tokens the templates ship.
   *          Deliberately does not assert any autofill behaviour — that is the
   *          browser's, not the product's.
   */
  test("login and lost-password fields declare their input purpose @fresh @login-forms", async ({ browser }) => {
    const visitor = await newVisitor(browser);
    const guest = await visitor.newPage();

    await guest.goto("/my-account/");
    await expect(guest.locator("#username")).toHaveAttribute("autocomplete", "username");
    await expect(guest.locator("#password")).toHaveAttribute("autocomplete", "current-password");

    await guest.locator(".ur-frontend-form a[href*='lost-password']").first().click();
    await expect(guest.locator("#user_login")).toHaveAttribute("autocomplete", "username");
    await visitor.close();
  });

  /**
   * @area    login-forms
   * @tier    fresh
   * @guards  #1725
   * @source  verify-fix 2026-10-05
   * @why     Every front-end form control had `outline: none` with no replacement
   *          that survived the per-form resets, so a keyboard user could not see
   *          where they were (WCAG 2.4.7). Asserts a drawn outline on a focused
   *          login field and on the submit button. Deliberately does not assert
   *          the ring's colour or width — those are the theme's to tune.
   */
  test("a focused login field and the login button show a visible outline @fresh @login-forms", async ({ browser }) => {
    const visitor = await newVisitor(browser);
    const guest = await visitor.newPage();
    await guest.goto("/my-account/");

    await guest.locator("#username").focus();
    await expect(guest.locator("#username")).toHaveCSS("outline-style", "solid");
    // themegrill/user-registration-pro#1896: no gap between the border and the ring, so it reads as one.
    await expect(guest.locator("#username")).toHaveCSS("outline-offset", "0px");

    await guest.locator(".ur-frontend-form button[type=submit], .ur-frontend-form .ur-submit-button").first().focus();
    await expect(
      guest.locator(".ur-frontend-form button[type=submit], .ur-frontend-form .ur-submit-button").first(),
    ).toHaveCSS("outline-style", "solid");
    await visitor.close();
  });
});

/**
 * themegrill/user-registration-pro#1664 — the Login and Lost Password inputs
 * carried only a decorative asterisk, so an empty submit went to the server and
 * came back as a page reload with the error in the URL. `required` lets the
 * browser stop it first. Asserted through the form's own validity, which is what
 * blocks the submit, rather than through the attribute alone.
 */
test.describe("required-field validation @fresh", () => {
  test("login and lost-password inputs are required before anything is sent @fresh @login-forms", async ({ page, browser }) => {
    await loginAsAdmin(page);
    await ensureFirstRun(page);

    const visitor = await newVisitor(browser);
    const user = await visitor.newPage();

    await user.goto("/my-account/");
    const login = user.locator("form.login");
    await expect(login.locator("input[name=username]")).toHaveAttribute("required", "");
    await expect(login.locator("input[name=password]")).toHaveAttribute("required", "");
    expect(await login.evaluate((form: HTMLFormElement) => form.checkValidity())).toBe(false);

    await user.locator(".user-registration-LostPassword a").first().click();
    await user.waitForLoadState("domcontentloaded");
    const lost = user.locator("input[name=user_login]");
    await expect(lost).toHaveAttribute("required", "");
    expect(await lost.evaluate((input: HTMLInputElement) => input.form?.checkValidity())).toBe(false);

    await visitor.close();
  });
});
