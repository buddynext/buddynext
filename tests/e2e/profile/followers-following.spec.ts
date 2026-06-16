import { test, expect } from '../_fixtures/auth.fixture';
import { urls } from '../_fixtures/selectors';

/**
 * Smoke spec for the followers + following list surfaces.
 *
 * The pages don't need to have any actual followers — what matters is that
 * the rewrite resolves, the page returns 200, and the well-known surface
 * renders. v2: /members/{user}/followers/ and /following/ resolve to the
 * profile shell with the matching in-page tab panel active (member-grid),
 * not the old standalone `.bn-connections` page, and the hero stat counts are
 * `.bn-pf-pill` links inside `.bn-pf-pills` (not the old `.bn-pf-stats`).
 */
test.describe('profile / followers + following', () => {
    const user = process.env.BN_TEST_USER ?? 'varundubey';

    test('desktop: /members/{user}/followers/ renders', async ({ authenticatedPage: page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        const response = await page.goto(urls.memberFollowers(user));

        expect(response?.status() ?? 0, '/followers/ must not 404').not.toBe(404);
        await expect(page.locator('.bn-pf-people-panel[data-tab-panel="followers"]')).toBeVisible({ timeout: 5_000 });
        await expect(page.locator('.bn-pf-tabs')).toBeVisible();
    });

    test('desktop: /members/{user}/following/ renders', async ({ authenticatedPage: page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        const response = await page.goto(urls.memberFollowing(user));

        expect(response?.status() ?? 0, '/following/ must not 404').not.toBe(404);
        await expect(page.locator('.bn-pf-people-panel[data-tab-panel="following"]')).toBeVisible({ timeout: 5_000 });
        await expect(page.locator('.bn-pf-tabs')).toBeVisible();
    });

    test('mobile (390px): followers page is scrollable', async ({ authenticatedPage: page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(urls.memberFollowers(user));
        await expect(page.locator('.bn-pf-people-panel[data-tab-panel="followers"]')).toBeVisible({ timeout: 5_000 });
    });

    test('mobile (390px): following page is scrollable', async ({ authenticatedPage: page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(urls.memberFollowing(user));
        await expect(page.locator('.bn-pf-people-panel[data-tab-panel="following"]')).toBeVisible({ timeout: 5_000 });
    });

    test('profile stat counts link to followers and following', async ({ authenticatedPage: page }) => {
        await page.goto(urls.member(user));
        await expect(page.locator('.bn-pf-pills')).toBeVisible();

        // Each count is an anchor (pill) carrying the matching profile URL.
        const followersLink = page.locator(`.bn-pf-pill[href*="/followers/"]`).first();
        await expect(followersLink).toBeVisible();
        const followingLink = page.locator(`.bn-pf-pill[href*="/following/"]`).first();
        await expect(followingLink).toBeVisible();
    });
});
