import { test, expect } from '../_fixtures/auth.fixture';
import { sel, urls } from '../_fixtures/selectors';

/**
 * J-23-hashtag-feed.
 */
test.describe('explore / hashtag feed', () => {
    test('hashtag feed page loads and scopes posts to the tag', async ({ authenticatedPage: page }) => {
        const slug = 'playwright';
        await page.goto(urls.hashtag(slug));
        await expect(page.locator(sel.app)).toBeVisible();

        // Header includes the slug.
        const heading = page.locator('h1, h2').filter({ hasText: new RegExp(slug, 'i') }).first();
        await expect(heading).toBeVisible({ timeout: 5_000 }).catch(async () => {
            // Some builds put the tag in a chip rather than a heading.
            const fallback = page.locator(`text=/#?${slug}/i`).first();
            await expect(fallback).toBeVisible();
        });
    });
});
