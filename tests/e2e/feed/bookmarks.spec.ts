import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from "../_fixtures/precondition";
import { sel, urls } from '../_fixtures/selectors';

/**
 * J-18-bookmark-post + J-19-share-post.
 */
test.describe('feed / bookmarks + share', () => {
    test('J-18 bookmark toggles active', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.feed);
        const firstCard = page.locator(sel.postCard).first();
        if ((await page.locator(sel.postCard).count()) === 0) {
            softSkip(testInfo, 'Feed empty.');
            return;
        }

        const btn = firstCard.locator(sel.postBookmark).first();
        if (!(await btn.isVisible().catch(() => false))) {
            softSkip(testInfo, 'No bookmark control in build.');
            return;
        }

        const beforePressed = await btn.getAttribute('aria-pressed').catch(() => null);
        await btn.click();
        const afterPressed = await btn.getAttribute('aria-pressed').catch(() => null);
        const isActive = await btn.evaluate((el) => el.classList.contains('is-active')).catch(() => false);
        expect(beforePressed !== afterPressed || isActive).toBeTruthy();
    });

    test('J-19 share opens a share popover', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.feed);
        if ((await page.locator(sel.postCard).count()) === 0) {
            softSkip(testInfo, 'Feed empty.');
            return;
        }
        const firstCard = page.locator(sel.postCard).first();
        const btn = firstCard.locator(sel.postShare).first();
        if (!(await btn.isVisible().catch(() => false))) {
            softSkip(testInfo, 'No share control in build.');
            return;
        }

        await btn.click();
        // Popover variants  -  accept any reasonable signal.
        const popover = page.locator('[role="menu"], [role="dialog"], .bn-share-popover, [data-share-popover]').first();
        await expect(popover).toBeVisible({ timeout: 4_000 });
    });
});
