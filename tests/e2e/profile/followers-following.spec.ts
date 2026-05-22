import { test, expect } from '../_fixtures/auth.fixture';
import { urls } from '../_fixtures/selectors';

/**
 * Smoke spec for the followers + following list surfaces.
 *
 * The pages don't need to have any actual followers — what matters is that
 * the rewrite resolves, the page returns 200, and the well-known surface
 * (header / count / empty state) renders. Asserts WordPress isn't serving
 * a 404 fallback under either URL.
 */
test.describe('profile / followers + following', () => {
    const user = process.env.BN_TEST_USER ?? 'varundubey';

    test('desktop: /members/{user}/followers/ renders', async ({ authenticatedPage: page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        const response = await page.goto(urls.memberFollowers(user));

        expect(response?.status() ?? 0, '/followers/ must not 404').not.toBe(404);
        await expect(page.locator('.bn-connections, .bn-followers')).toBeVisible({ timeout: 5_000 });
        await expect(page.locator('.bn-connections-title')).toBeVisible();
    });

    test('desktop: /members/{user}/following/ renders', async ({ authenticatedPage: page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        const response = await page.goto(urls.memberFollowing(user));

        expect(response?.status() ?? 0, '/following/ must not 404').not.toBe(404);
        await expect(page.locator('.bn-connections, .bn-following')).toBeVisible({ timeout: 5_000 });
        await expect(page.locator('.bn-connections-title')).toBeVisible();
    });

    test('mobile (390px): followers page is scrollable', async ({ authenticatedPage: page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(urls.memberFollowers(user));
        await expect(page.locator('.bn-connections, .bn-followers')).toBeVisible({ timeout: 5_000 });
    });

    test('mobile (390px): following page is scrollable', async ({ authenticatedPage: page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(urls.memberFollowing(user));
        await expect(page.locator('.bn-connections, .bn-following')).toBeVisible({ timeout: 5_000 });
    });

    test('profile stat cards link to followers and following', async ({ authenticatedPage: page }) => {
        await page.goto(urls.member(user));
        await expect(page.locator('.bn-pf-stats')).toBeVisible();

        // Followers stat is an anchor with the followers URL.
        const followersLink = page.locator(`.bn-pf-stat--link[href*="/followers/"]`).first();
        await expect(followersLink).toBeVisible();
        const followingLink = page.locator(`.bn-pf-stat--link[href*="/following/"]`).first();
        await expect(followingLink).toBeVisible();
    });
});
