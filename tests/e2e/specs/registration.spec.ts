import { expect, test } from "@playwright/test";
import { ensureFirstRun, firstFormId, registerOn, registrationPageFor } from "../support/urm";
import { deleteUserByEmail, loginAsAdmin, newVisitor } from "../support/wp";

/**
 * Ported from UR-Automation `02__form_settings` — "Validate User Approval and
 * Login Options (Auto Approval and Manual Login)" (tag ci_cd) — and the
 * registration half of `04__setting_general_options`.
 *
 * The Robot original rebuilt a form through the builder UI first. That is not
 * what the test is about: its assertions are all about what happens on submit.
 * Building the form is setup, so this uses the site's existing form and spends
 * its assertions on the outcome instead.
 */
test.describe("registration @fresh", () => {
  test("a visitor can register and the account is created @fresh @registration", async ({ page, browser }) => {
    await loginAsAdmin(page);
    await ensureFirstRun(page);
    const formId = await firstFormId(page);
    const url = await registrationPageFor(page, formId);

    // A real visitor is not logged in; reuse of the admin session here would
    // hide any capability bug in the front-end submit path.
    const visitor = await newVisitor(browser);
    const guest = await visitor.newPage();
    const account = await registerOn(guest, url);

    // A successful registration either prints a notice in place or, when a
    // membership plan is involved, redirects to the thank-you page. Accept
    // both: which one happens is form configuration, not correctness.
    const notice = guest.locator(".ur-message, .user-registration-message, .ur-error").first();
    await expect(notice).toBeVisible();
    const message = await notice.innerText();
    expect(message, `registration returned: ${message}`).toMatch(
      /success|registered|verify|approval|confirm|thank you/i,
    );
    expect(message, "registration reported an error").not.toMatch(/is required|invalid|already/i);

    // The notice is not the evidence — the user row is. This product's history
    // is full of success messages printed over a write that did not happen.
    await page.goto(`/wp-admin/users.php?s=${encodeURIComponent(account.email)}`);
    await expect(page.locator("#the-list")).toContainText(account.username);

    await deleteUserByEmail(page, account.email);
    await visitor.close();
  });

  /**
   * @area    registration
   * @tier    fresh
   * @guards  #1725
   * @source  verify-fix 2026-10-05
   * @why     The required-field asterisk was #ff4f55, 3.23:1 on white, below the
   *          4.5:1 minimum for body text (WCAG 1.4.3) and the only required cue
   *          on the form. Computes the ratio from the rendered colour against the
   *          first opaque ancestor background (white if none). Deliberately does
   *          not pin the exact hex.
   */
  test("required-field asterisks meet the 4.5:1 text contrast minimum @fresh @registration", async ({ page, browser }) => {
    await loginAsAdmin(page);
    await ensureFirstRun(page);
    const url = await registrationPageFor(page, await firstFormId(page));

    const visitor = await newVisitor(browser);
    const guest = await visitor.newPage();
    await guest.goto(url);

    const marker = guest.locator(".ur-frontend-form .required").first();
    await expect(marker).toBeVisible();
    const ratio = await marker.evaluate((el) => {
      const rgb = (css: string) => css.match(/[\d.]+/g)!.map(Number);
      const lin = (c: number) => ((c /= 255) <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4);
      const luminance = ([r, g, b]: number[]) => 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
      let node: Element | null = el;
      let background = [255, 255, 255];
      while (node) {
        const [r, g, b, a = 1] = rgb(getComputedStyle(node).backgroundColor);
        if (a === 1) {
          background = [r, g, b];
          break;
        }
        node = node.parentElement;
      }
      const [lighter, darker] = [luminance(rgb(getComputedStyle(el).color)), luminance(background)].sort((x, y) => y - x);
      return (lighter + 0.05) / (darker + 0.05);
    });
    expect(ratio, "asterisk contrast against its background").toBeGreaterThanOrEqual(4.5);
    await visitor.close();
  });
});
