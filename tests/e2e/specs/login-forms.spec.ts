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
});
