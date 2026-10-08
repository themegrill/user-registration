import { expect, test, type Page } from "@playwright/test";
import { gotoAdminPage, loginAsAdmin, restNonce } from "../support/wp";

const WIZARD_API = "/index.php?rest_route=/user-registration/v1/getting-started";
const WELCOME_STEPS = {
  membership: ["Welcome", "Membership", "Payment", "Finish"],
  registration: ["Welcome", "Settings", "Finish"],
};
const ALL_STEP_LABELS = ["Membership", "Payment", "Settings"];

type Welcome = { membership_type: string; allow_usage_tracking: boolean; admin_email: string };

async function readWelcome(page: Page): Promise<Welcome> {
  const nonce = await restNonce(page);
  const response = await page.request.get(`${WIZARD_API}/welcome`, {
    headers: { "X-WP-Nonce": nonce },
  });
  expect(response.ok()).toBeTruthy();
  return (await response.json()).data;
}

/** Store a membership type the way the wizard's own Next button does. */
async function seedMembershipType(page: Page, membershipType: string, welcome: Welcome) {
  const nonce = await restNonce(page);
  const response = await page.request.post(`${WIZARD_API}/welcome`, {
    headers: { "X-WP-Nonce": nonce },
    data: {
      membership_type: membershipType,
      allow_usage_tracking: welcome.allow_usage_tracking,
      admin_email: welcome.admin_email,
    },
  });
  expect(response.ok()).toBeTruthy();
}

/** Open the wizard and wait for its saved answer to load, which is the last request it makes. */
async function openWizard(page: Page) {
  const hydrated = page.waitForResponse(
    (response) => /getting-started(?:%2F|\/)welcome/.test(response.url()) && response.request().method() === "GET",
  );
  await gotoAdminPage(page, "user-registration-welcome", "&tab=setup-wizard");
  await hydrated;
  await expect(page.getByRole("radio", { name: "Yes" })).toBeAttached();
}

async function expectStepper(page: Page, steps: string[]) {
  for (const label of steps) {
    await expect(page.getByText(label, { exact: true }).first()).toBeVisible();
  }
  for (const label of ALL_STEP_LABELS.filter((l) => !steps.includes(l))) {
    await expect(page.getByText(label, { exact: true })).toHaveCount(0);
  }
}

test.describe("setup wizard membership question @fresh", () => {
  let original: Welcome;

  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    original = await readWelcome(page);
  });

  test.afterEach(async ({ page }) => {
    if (original.membership_type) {
      await seedMembershipType(page, original.membership_type, original);
    }
  });

  test("stored normal loads as Not now with the three-step stepper @fresh @setup-wizard", async ({ page }) => {
    await seedMembershipType(page, "normal", original);
    await openWizard(page);

    await expect(page.getByRole("radio", { name: "Not now" })).toBeChecked();
    await expect(page.getByRole("radio", { name: "Yes" })).not.toBeChecked();
    await expectStepper(page, WELCOME_STEPS.registration);
  });

  test("stored paid_membership and legacy free_membership both load as Yes @fresh @setup-wizard", async ({ page }) => {
    for (const stored of ["paid_membership", "free_membership"]) {
      await seedMembershipType(page, stored, original);
      await openWizard(page);

      await expect(page.getByRole("radio", { name: "Yes" })).toBeChecked();
      await expectStepper(page, WELCOME_STEPS.membership);
    }
  });

  test("a stored free_membership keeps the Payment step unlocked on the server @fresh @setup-wizard", async ({ page }) => {
    await seedMembershipType(page, "free_membership", original);
    const nonce = await restNonce(page);

    const memberships = await (
      await page.request.get(`${WIZARD_API}/memberships`, { headers: { "X-WP-Nonce": nonce } })
    ).json();
    expect(memberships.membership_type).toBe("paid_membership");
    expect(memberships.can_create_paid).toBe(true);

    const progress = await (
      await page.request.get(WIZARD_API, { headers: { "X-WP-Nonce": nonce } })
    ).json();
    const payment = progress.data.steps.find((step: { id: string }) => step.id === "payment");
    expect(payment.is_accessible).toBe(true);
  });

  test("choosing Not now and clicking Next saves normal and reload keeps the card @fresh @setup-wizard", async ({ page }) => {
    await seedMembershipType(page, "paid_membership", original);
    await openWizard(page);

    await page.getByText("Not now", { exact: true }).click();
    const saved = page.waitForResponse(
      (response) => response.url().includes("getting-started/welcome") && response.request().method() === "POST",
    );
    await page.getByRole("button", { name: /Next/ }).click();
    expect((await saved).request().postDataJSON().membership_type).toBe("normal");

    await openWizard(page);
    await expect(page.getByRole("radio", { name: "Not now" })).toBeChecked();
  });

  test("choosing Yes and clicking Next saves paid_membership @fresh @setup-wizard", async ({ page }) => {
    await seedMembershipType(page, "normal", original);
    await openWizard(page);

    await page.getByText("Yes", { exact: true }).click();
    const saved = page.waitForResponse(
      (response) => response.url().includes("getting-started/welcome") && response.request().method() === "POST",
    );
    await page.getByRole("button", { name: /Next/ }).click();
    expect((await saved).request().postDataJSON().membership_type).toBe("paid_membership");
  });
});
