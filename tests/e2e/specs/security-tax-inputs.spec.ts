import { expect, test } from "@playwright/test";
import { readFile } from "node:fs/promises";
import path from "node:path";

// Allow CI/local runs to reuse an installed Chromium executable.
if (process.env.UR_SECURITY_CHROMIUM_BINARY) {
  test.use({ launchOptions: { executablePath: process.env.UR_SECURITY_CHROMIUM_BINARY } });
}

test("Tax input construction safely handles stored payloads @fresh @security", async ({ page }) => {
  const root = path.resolve(__dirname, "../../..");
  await page.setContent('<span id="ur-membership-total"></span>');
  await page.addScriptTag({ path: path.join(root, "node_modules/jquery/dist/jquery.js") });
  const source = await readFile(path.join(root, "assets/js/frontend/user-registration.js"), "utf8");
  const start = source.indexOf("function calculate_total(membershipData, taxRate) {");
  const end = source.indexOf("\n})(jQuery);", start);
  expect(start).toBeGreaterThan(0);
  await page.addScriptTag({ content: "window.$ = jQuery; window.user_registration_params = { is_tax_calculation_activated: true, currency_pos: 'left', currency_symbol: '$', tax_calculation_method: 'exclusive' }; " + source.slice(start, end) });
  for (const rate of ['<script>window.taxXss = true</script>', '"><img src=x onerror="window.taxXss=true">', 'x" onclick="window.taxXss=true', 'Infinity', '-1', '101', '7.25']) {
    await page.evaluate((value) => {
      (window as any).calculate_total({ total: 100 }, value);
    }, rate);
    await expect(page.locator("#ur-tax-details")).toHaveAttribute("data-tax-rate", rate === "7.25" ? "7.25" : "0");
    await expect(page.locator("img")).toHaveCount(0);
    expect(await page.evaluate(() => (window as any).taxXss)).toBeUndefined();
  }
  // Exercise the exact membership input construction too, bypassing unrelated checkout logic.
  const membership = await readFile(path.join(root, "assets/js/modules/membership/frontend/user-registration-membership-frontend.js"), "utf8");
  const inputStart = membership.indexOf('var taxDetailsInput = $("<input>", {');
  const inputEnd = membership.indexOf("total_input.after(taxDetailsInput);", inputStart) + "total_input.after(taxDetailsInput);".length;
  expect(inputStart).toBeGreaterThan(0);
  await page.addScriptTag({ content: `$("#ur-tax-details").remove(); var total_input = $("#ur-membership-total"), taxRate = ${JSON.stringify('"><img src=x onerror="window.taxXss=true">')}, total = 100, tax_calculation_method = "exclusive"; ` + membership.slice(inputStart, inputEnd) });
  await expect(page.locator("img")).toHaveCount(0);
  expect(await page.evaluate(() => (window as any).taxXss)).toBeUndefined();
});
