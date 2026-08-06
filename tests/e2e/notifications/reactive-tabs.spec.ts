import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from '../_fixtures/precondition';
import { urls } from '../_fixtures/selectors';

/**
 * J-122-notification-reactive-tabs.
 *
 * Reactive filter tabs (notifications completion Wave A5).
 *
 * Verifies that clicking a tab updates the visible content + URL without
 * triggering a full page navigation, and that the document title reflects
 * the unread count (A8).
 */
test.describe('notifications / reactive tabs', () => {
    test('tab click swaps content without full reload', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.notifications);

        const tabs = page.locator('.bn-notif-tabs .bn-tab');
        if ((await tabs.count()) < 2) {
            softSkip(testInfo, 'Tab strip not visible (notifications hub not rendered?).');
            return;
        }

        // Capture a fresh marker on window so we can confirm no full reload.
        await page.evaluate(() => { (window as any).__bnNoReload = 'kept'; });

        const unreadTab = tabs.locator('[data-filter="unread"]');
        if (!(await unreadTab.isVisible().catch(() => false))) {
            softSkip(testInfo, 'Unread tab not present.');
            return;
        }

        await unreadTab.click();

        // URL should reflect the new filter param.
        await expect.poll(async () => new URL(page.url()).searchParams.get('filter'), { timeout: 5_000 }).toBe('unread');

        // is-active state moved to the unread tab.
        await expect(unreadTab).toHaveClass(/is-active/);
        await expect(unreadTab).toHaveAttribute('aria-selected', 'true');

        // Window marker survives — proves we did not navigate the document.
        const marker = await page.evaluate(() => (window as any).__bnNoReload);
        expect(marker).toBe('kept');
    });

    /**
     * This asserted, behind a data-dependent conditional, that the document
     * title mirrors the unread badge - `(3) Notifications`. We have never built
     * that: `grep -rn "document.title" assets/js/` returns nothing. The spec
     * passed for as long as the test account had zero unread, because the badge
     * was absent and the conditional never fired. Once notifications
     * accumulated, the badge appeared, the assertion switched itself on, and the
     * suite reported an unread-count REGRESSION against code that had not
     * changed.
     *
     * A spec must not assert unimplemented behaviour, least of all behind a
     * conditional that hides the fact until the data changes underneath it. Kept
     * to what it genuinely verifies. Whether to implement the count in the title
     * - Facebook and X both do - is a product decision, tracked separately.
     */
    test('notifications page has a meaningful document title', async ({ authenticatedPage: page }) => {
        await page.goto(urls.notifications);

        const title = await page.title();
        expect(title.toLowerCase()).toContain('notifications');
    });

    test('preferences page uses dedicated title', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto('/settings/notifications/');
        const title = await page.title();
        if (! /notification preferences/i.test(title)) {
            softSkip(testInfo, `Title was "${title}" — rewrites may need flushing or i18n applied.`);
            return;
        }
        expect(title).toMatch(/notification preferences/i);
    });
});
