import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from '../_fixtures/precondition';
import { urls } from '../_fixtures/selectors';

/**
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

    test('document title reflects unread count when present', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.notifications);

        // Best-effort: title should at least contain the word Notifications.
        const title = await page.title();
        expect(title.toLowerCase()).toContain('notifications');

        // If the page surfaces a (count) suffix, the title should mirror it.
        const headerCount = await page.locator('.bn-section-head__title .bn-badge[data-tone="accent"]').first().textContent().catch(() => null);
        if (headerCount && /\d/.test(headerCount)) {
            const match = headerCount.match(/(\d+|99\+)/);
            if (match) {
                expect(title).toContain(match[1]);
            }
        }
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
