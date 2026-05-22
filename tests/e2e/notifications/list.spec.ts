import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from "../_fixtures/precondition";
import { sel, urls } from '../_fixtures/selectors';

/**
 * J-48 list + J-49 mark read + J-50 mark all read.
 */
test.describe('notifications / list', () => {
    test('J-48 notifications page renders list or empty state', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.notifications);
        await expect(page.locator(sel.app)).toBeVisible();
        const list = page.locator(sel.notifList).first();
        const empty = page.locator('.bn-notif-empty, [data-notif-empty]').first();
        expect((await list.isVisible().catch(() => false)) || (await empty.isVisible().catch(() => false))).toBeTruthy();
    });

    test('J-49 clicking an unread notification marks it read', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.notifications);
        const unread = page.locator('.bn-notif-item.is-unread, [data-notif-item][data-read="0"]').first();
        if (!(await unread.isVisible().catch(() => false))) {
            softSkip(testInfo, 'No unread notifications to mark.');
            return;
        }
        await unread.click();
        // After click the item should lose its unread class OR navigate away.
        const stillUnread = await unread.evaluate((el) => el.classList.contains('is-unread')).catch(() => false);
        expect(stillUnread).toBeFalsy();
    });

    test('J-50 mark all read button drops unread count', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.notifications);
        const btn = page.locator(sel.notifMarkAll).first();
        if (!(await btn.isVisible().catch(() => false))) {
            softSkip(testInfo, 'No mark-all-read button visible (nothing unread).');
            return;
        }
        await btn.click();
        await expect.poll(async () => {
            return page.locator('.bn-notif-item.is-unread, [data-notif-item][data-read="0"]').count();
        }, { timeout: 5_000 }).toBe(0);
    });
});
