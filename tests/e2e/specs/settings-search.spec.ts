import { expect, test, type Page } from "@playwright/test";
import { gotoAdminPage, loginAsAdmin } from "../support/wp";

/**
 * Regression for themegrill/user-registration-pro#1679 — the Settings search
 * returned "No Search result found !" for every setting that sits behind a
 * settings section, matched on field types, and showed junk rows labelled
 * `true`.
 */
const NO_RESULT_VALUE = "no_result_found";

type SearchResult = { label: string; location: string; value: string };

/** Ask the real search endpoint, with the page's own nonce, what a query returns. */
async function search(page: Page, term: string): Promise<SearchResult[]> {
  return await page.evaluate(async (term) => {
    const params = (window as any).user_registration_settings_params;
    const body = new FormData();
    body.append("search_string", term);
    body.append("action", "user_registration_search_global_settings");
    body.append("security", params.user_registration_search_global_settings_nonce);
    const response = await fetch(params.ajax_url, { method: "POST", body });
    return (await response.json()).data.results;
  }, term);
}

test.describe("settings search @fresh", () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await gotoAdminPage(page, "user-registration-settings");
  });

  test("finds settings that live inside a section, and says where @fresh", async ({ page }) => {
    const currency = await search(page, "currency");
    expect(currency.map((r) => `${r.label} | ${r.location}`)).toContain("Currency | Payment → Store");

    const logout = await search(page, "logout");
    expect(logout.map((r) => `${r.label} | ${r.location}`)).toContain("User Logout | My Account → Endpoints");
  });

  test("matches on text a user can read, never on a field's type @fresh", async ({ page }) => {
    for (const fieldType of ["toggle", "checkbox"]) {
      const results = await search(page, fieldType);
      expect(results.map((r) => r.value)).toEqual([NO_RESULT_VALUE]);
    }
  });

  test("ignores the prefix every setting id shares, and too-short queries @fresh", async ({ page }) => {
    for (const term of ["urm_", "ab", ""]) {
      const results = await search(page, term);
      expect(results.map((r) => r.value)).toEqual([NO_RESULT_VALUE]);
    }
  });

  test("never returns a row labelled true @fresh", async ({ page }) => {
    for (const term of ["email", "page", "enable", "secret"]) {
      const labels = (await search(page, term)).map((r) => r.label);
      expect(labels).not.toContain("true");
    }
  });

  test("a query with no match says so plainly @fresh", async ({ page }) => {
    const results = await search(page, "zzzqqqxxx");
    expect(results).toHaveLength(1);
    expect(results[0]).toMatchObject({ label: "No settings found", value: NO_RESULT_VALUE });
  });

  test("finds the sections only Pro adds, even though one of them prints markup while loading @fresh", async ({ page }) => {
    // Pro's Popups section echoes a table while its settings are built; that output once corrupted the JSON.
    await gotoAdminPage(page, "user-registration-settings", "&tab=payment");
    const proSectionsPresent = (await page.locator("a[href*='section=payment-retry']").count()) > 0;
    test.skip(!proSectionsPresent, "needs the Pro Payment sections");

    const tax = await search(page, "tax");
    expect(tax.map((r) => `${r.label} | ${r.location}`)).toContain("Calculate tax at checkout | Payment → Tax & VAT");

    const retry = await search(page, "retry");
    expect(retry.map((r) => `${r.label} | ${r.location}`)).toContain("Enable Payment Retry | Payment → Payment Retry & Dunning");
  });

  test("picking a result opens its tab and section and highlights the field @fresh", async ({ page }) => {
    await page.locator("#ur-search-settings").pressSequentially("currency");

    const row = page.locator(".user-registration-ui-autocomplete li").filter({ hasText: "Payment → Store" });
    await expect(row).toHaveCount(1);
    await row.click();

    await expect(page).toHaveURL(/tab=payment&section=store&searched_option=/);
    await expect(page.locator(".ur-searched-settings-focus")).toHaveCount(1);
  });
});
