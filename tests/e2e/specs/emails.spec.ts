import { expect, test } from "@playwright/test";
import { addressedTo, mailAvailable, waitForMessage } from "../support/mail";
import { ensureFirstRun, firstFormId, registerOn, registrationPageFor } from "../support/urm";
import { deleteUserByEmail, loginAsAdmin, newVisitor } from "../support/wp";

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
});
