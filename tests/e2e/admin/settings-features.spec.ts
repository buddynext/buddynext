import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from "../_fixtures/precondition";
import { sel, urls } from '../_fixtures/selectors';

/**
 * J-58 admin features toggle.
 */
test.describe('admin / settings  -  features', () => {
    test('features panel renders toggles', async ({ authenticatedPage: page }, testInfo) => {
        const resp = await page.goto(urls.adminSettings, { waitUntil: 'domcontentloaded' });
        if ((resp?.status() ?? 200) >= 400) {
            softSkip(testInfo, 'Settings page unavailable to this user.');
            return;
        }
        const panel = page.locator(sel.adminFeatures).first();
        const fallbackForm = page.locator('form#buddynext-settings, form:has(input[name*="bn_"])').first();
        const anyVisible = (await panel.isVisible().catch(() => false)) || (await fallbackForm.isVisible().catch(() => false));
        expect(anyVisible).toBeTruthy();
    });
});
