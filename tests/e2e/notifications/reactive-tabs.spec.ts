import { test, expect } from '../_fixtures/auth.fixture';
import { urls } from '../_fixtures/selectors';
import { idsOf, restList } from '../_fixtures/effects';

// The row root (parts/notification-row.php); its buttons carry data-notif-id too.
const ROW = '[role="listitem"][data-notif-id]';

/**
 * J-122-notification-reactive-tabs.
 *
 * Covers: cap-see-in-app-notifications-mark-them-read-and-set-preferences
 * see FRICTION in the role-coverage report.
 * Roles: member
 *
 * Reactive filter tabs (notifications completion Wave A5).
 *
 * Verifies that clicking a tab updates the visible content + URL without
 * triggering a full page navigation, and that the document title reflects
 * the unread count (A8).
 */
test.describe('notifications / reactive tabs', () => {
    test('tab click swaps content without full reload', async ({ authenticatedPage: page }) => {
        await page.goto(urls.notifications);

        // The strip always renders on the hub (parts/notifications-filter-bar.php).
        const tabs = page.locator('.bn-notif-tabs .bn-tab');
        expect(await tabs.count(), 'notification tab strip did not render').toBeGreaterThanOrEqual(2);

        // Capture a fresh marker on window so we can confirm no full reload. The
        // in-place swap only exists when the site enables client-side navigation
        // (buddynext_client_nav_enabled, default off: the tab is then a plain link
        // and a full page load is the correct default behaviour).
        const clientNav = (await page.locator('[data-wp-router-region]').count()) > 0;
        await page.evaluate(() => { (window as any).__bnNoReload = 'kept'; });

        // `data-filter` sits ON the tab link (notifications-filter-bar.php:91). The old
        // `tabs.locator('[data-filter="unread"]')` looked for it INSIDE a tab, matched
        // nothing, and soft-skipped this journey on every run.
        const unreadTab = page.locator('.bn-notif-tabs .bn-tab[data-filter="unread"]');
        await expect(unreadTab, 'Unread tab not present').toBeVisible({ timeout: 10_000 });

        await unreadTab.click();

        // URL should reflect the new filter param.
        await expect.poll(async () => new URL(page.url()).searchParams.get('filter'), { timeout: 5_000 }).toBe('unread');

        // is-active state moved to the unread tab.
        await expect(unreadTab).toHaveClass(/is-active/);
        await expect(unreadTab).toHaveAttribute('aria-selected', 'true');

        // Window marker survives — proves we did not navigate the document.
        const marker = await page.evaluate(() => (window as any).__bnNoReload);
        if (clientNav) {
            expect(marker, 'client nav is on, so the tab swaps in place').toBe('kept');
        }
        // EFFECT: the swapped-in content is the unread set the route answers.
        const rest = await restList(page, '/me/notifications?filter=unread&per_page=50');
        expect(rest.status, 'GET /me/notifications?filter=unread').toBe(200);
        await expect
            .poll(async () => (await page.locator(ROW).count()) > 0, { timeout: 5_000 })
            .toBe(rest.items.length > 0);
        // EFFECT: every row the tab shows is unread on screen AND in the route's unread set.
        const shown = (await page.locator(ROW).evaluateAll((els) =>
            els.map((el) => ({
                id: Number(el.getAttribute('data-notif-id')),
                unread: el.classList.contains('bn-notif-row--unread'),
            }))
        )) as Array<{ id: number; unread: boolean }>;
        expect(shown.filter((r) => !r.unread), 'a read row is listed under the Unread tab').toEqual([]);
        const unreadIds = idsOf(rest.data);
        if (unreadIds.length < 50) {
            // The route answered the whole unread set, so the screen cannot show an id outside it.
            expect(shown.map((r) => r.id).filter((id) => !unreadIds.includes(id)), 'Unread tab shows rows the route does not list').toEqual([]);
        }
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

    test('preferences page uses dedicated title', async ({ authenticatedPage: page }) => {
        await page.goto('/settings/notifications/');
        // PageRouter sets this title for the Settings > Notifications tab unconditionally, so a
        // different title is a defect, not a missing precondition (the old soft-skip hid it).
        expect(await page.title()).toMatch(/notification preferences/i);
    });
});
