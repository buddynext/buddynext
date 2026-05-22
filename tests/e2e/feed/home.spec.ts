import { test, expect } from '../_fixtures/auth.fixture';
import { sel, urls } from '../_fixtures/selectors';

/**
 * J-11-feed-home-loads.
 */
test.describe('feed / home', () => {
    test('home feed renders shell + composer', async ({ authenticatedPage: page }) => {
        await page.goto(urls.feed);
        await expect(page.locator(sel.app)).toBeVisible();
        await expect(page.locator(sel.appMain)).toBeVisible();

        // Composer is present (logged-in only).
        const composerCount = await page.locator(sel.composer).count();
        expect(composerCount).toBeGreaterThan(0);
    });

    test('home feed renders posts OR empty state', async ({ authenticatedPage: page }) => {
        await page.goto(urls.feed);
        await expect(page.locator(sel.app)).toBeVisible();

        const postCount = await page.locator(sel.postCard).count();
        const empty = await page.locator(sel.feedEmpty).count();
        expect(postCount > 0 || empty > 0).toBeTruthy();
    });
});
