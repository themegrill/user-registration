import { expect, test } from "@playwright/test";
import { addressedTo, mailAvailable, messageHtml, waitForMessage } from "../support/mail";
import { ensureFirstRun, firstFormId, registerOn, registrationPageFor } from "../support/urm";
import { uniqueEmail } from "../support/env";
import { deleteUserByEmail, gotoAdminPage, loginAsAdmin, newVisitor } from "../support/wp";

/**
 * Ported from UR-Automation `06__email_related_tests` — "Validate Admin Email
 * and Successfully Registered Email" (tag ci_cd).
 *
 * Needs a mail catcher. Local by Flywheel runs Mailpit per site; point
 * TGQA_MAILPIT_URL at another one in CI. Without it these SKIP rather than
 * fail, because a red test for missing infrastructure trains people to ignore
 * red tests.
 */
test.describe("registration emails @fresh", () => {
  test("send test email blocks repeated clicks while the request is pending @fresh @admin", async ({ page }) => {
    await loginAsAdmin(page);

    let sendRequests = 0;
    let releaseResponse!: () => void;
    let resolveFirstRequest!: () => void;
    const firstRequest = new Promise<void>((resolve) => {
      resolveFirstRequest = resolve;
    });

    await page.route("**/wp-admin/admin-ajax.php", async (route) => {
      const request = route.request();
      if (request.method() !== "POST" || !request.postData()?.includes("action=user_registration_send_test_email")) {
        await route.continue();
        return;
      }

      sendRequests++;
      resolveFirstRequest();
      await new Promise<void>((release) => {
        releaseResponse = release;
      });
      await route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({ success: true, data: { message: "Test email sent." } }),
      });
    });

    await gotoAdminPage(page, "user-registration-settings", "&tab=email");
    const button = page.locator(".user_registration_send_email_test");
    await button.click();
    await firstRequest;

    await expect(button).toHaveClass(/disabled/);
    await expect(button).toHaveAttribute("aria-disabled", "true");
    await expect(button.locator(".ur-spinner")).toHaveCount(1);

    await button.evaluate((element) => {
      element.click();
      element.click();
    });
    await page.waitForTimeout(250);

    expect(sendRequests).toBe(1);
    await expect(button.locator(".ur-spinner")).toHaveCount(1);

    releaseResponse();
    await expect(button).not.toHaveClass(/disabled/);
    await expect(button).not.toHaveAttribute("aria-disabled", "true");
    await expect(button.locator(".ur-spinner")).toHaveCount(0);
  });

  test("registering sends the user a welcome email and notifies the admin @fresh @email-notification", async ({
    page,
    browser,
  }) => {
    test.skip(
      !(await mailAvailable()),
      "no mail catcher reachable — set TGQA_MAILPIT_URL",
    );

    await loginAsAdmin(page);
    await ensureFirstRun(page);
    const url = await registrationPageFor(page, await firstFormId(page));

    // Anything already in the mailbox predates this; the unique address plus
    // this cutoff is what isolates the assertion without deleting anyone's mail.
    const cutoff = Date.now() - 5_000;

    const visitor = await newVisitor(browser);
    const guest = await visitor.newPage();
    const account = await registerOn(guest, url);

    const welcome = await waitForMessage(
      (m) => addressedTo(account.email)(m) && Date.parse(m.Created) >= cutoff,
    );
    expect(welcome, `no email was delivered to ${account.email}`).not.toBeNull();

    // The admin notification carries the new username in its subject. Assert on
    // that rather than on the recipient: Local rewrites the admin address to
    // dev-email@wpengine.local, and a real site would use its own.
    const adminNotice = await waitForMessage(
      (m) =>
        Date.parse(m.Created) >= cutoff &&
        m.Subject?.includes(account.username) &&
        !addressedTo(account.email)(m),
    );
    expect(
      adminNotice,
      `no admin notification mentioning ${account.username}`,
    ).not.toBeNull();

    await deleteUserByEmail(page, account.email);
    await visitor.close();
  });

  /**
   * Regression for themegrill/user-registration-pro#1644: "Send Test Email" used
   * to hand a bare string to wp_mail(), so the email wrapper, header and footer
   * configured for real notifications never applied.
   */
  test("Send Test Email is wrapped in the notification email template @fresh @email-notification", async ({
    page,
  }) => {
    test.skip(
      !(await mailAvailable()),
      "no mail catcher reachable — set TGQA_MAILPIT_URL",
    );

    await loginAsAdmin(page);
    await gotoAdminPage(page, "user-registration-settings", "&tab=email");

    const recipient = uniqueEmail("testmail");
    const cutoff = Date.now() - 5_000;
    await page.fill("#user_registration_email_send_to", recipient);
    await page.click(".user_registration_send_email_test");
    await expect(page.locator(".notice-success")).toBeVisible();

    const sent = await waitForMessage(
      (m) => addressedTo(recipient)(m) && Date.parse(m.Created) >= cutoff,
    );
    expect(sent, `no test email was delivered to ${recipient}`).not.toBeNull();

    const html = await messageHtml(sent!.ID);
    expect(html, "the delivered message has no HTML body").not.toBeNull();
    expect(html).toContain("email-wrapper-outer");
    expect(html).toContain("email-body");
    expect(html).toMatch(/<p style="[^"]+">Your test email has been received successfully\.<\/p>/);
  });
});
