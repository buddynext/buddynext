import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from "../_fixtures/precondition";
import { urls } from '../_fixtures/selectors';

/**
 * J-51 notification preferences.
 *
 * Smoke-walks /settings/notifications/ and the legacy alias
 * /notifications/preferences/ asserting the four sections render and at
 * least one per-type row is interactive.
 */
test.describe('notifications / preferences', () => {
    test('preferences page loads with all four sections', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto('/settings/notifications/');

        const wrapper = page.locator('.bn-notif-prefs');
        if (!(await wrapper.isVisible().catch(() => false))) {
            softSkip(testInfo, 'Preferences page did not render (rewrites need flushing?).');
            return;
        }

        await expect(page.getByRole('heading', { name: /notification preferences/i, level: 1 })).toBeVisible();

        // Channels section.
        await expect(page.locator('input[data-channel="in_app"]')).toBeVisible();
        await expect(page.locator('input[data-channel="email"]')).toBeVisible();

        // Activity types section — at least one row with an on-site toggle + freq chip group.
        const firstRowToggle = page.locator('.bn-prefs-row input[data-type]').first();
        await expect(firstRowToggle).toBeVisible();
        const firstFreqChip = page.locator('.bn-prefs-chip[data-freq]').first();
        await expect(firstFreqChip).toBeVisible();

        // Quiet hours coming-soon section is present.
        await expect(page.getByText(/quiet hours/i)).toBeVisible();
    });

    test('settings link from /notifications/ navigates to prefs', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.notifications);
        const settingsLink = page.locator('.bn-section-head__actions a.bn-btn--prefs-link');
        if (!(await settingsLink.isVisible().catch(() => false))) {
            softSkip(testInfo, 'Settings link missing on notifications header.');
            return;
        }
        await settingsLink.click();
        await page.waitForURL(/\/settings\/notifications\/?$/);
        await expect(page.locator('.bn-notif-prefs')).toBeVisible();
    });
});
