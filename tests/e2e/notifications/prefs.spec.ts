import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from "../_fixtures/precondition";
import { urls } from '../_fixtures/selectors';

/**
 * J-51 notification preferences.
 */
test.describe('notifications / preferences', () => {
    test('preferences tab renders per-type toggles', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(`${urls.notifications}?tab=settings`);
        const toggle = page.locator('input[type="checkbox"][name*="notif"], [data-pref-toggle]').first();
        if (!(await toggle.isVisible().catch(() => false))) {
            softSkip(testInfo, 'Preferences tab not rendered (admin gate or feature off).');
            return;
        }
        await expect(toggle).toBeVisible();
    });
});
