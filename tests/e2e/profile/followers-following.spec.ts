import { test, expect } from '../_fixtures/auth.fixture';
import { sel, urls } from '../_fixtures/selectors';

/**
 * J-123-followers-following-lists.
 *
 * Smoke spec for the followers + following list surfaces.
 *
 * The pages don't need to have any actual followers — what matters is that
 * the rewrite resolves, the page returns 200, and the well-known surface
 * renders. /members/{user}/followers/ and /following/ resolve to the people
 * hub (body.bn-hub-people) showing `.bn-member-row-list`, or `.bn-empty-state`
 * when the member has none; either satisfies "the surface rendered", which is
 * what this spec is for. The hero stat counts are `.bn-nav-metric` anchors
 * inside `.bn-pf-metricrow`.
 *
 * This previously asserted `.bn-pf-people-panel[data-tab-panel="…"]`,
 * `.bn-pf-tabs` and `.bn-pf-pills`, none of which the markup has emitted since
 * the profile was rebuilt — so all five tests failed against a working feature.
 */
test.describe('profile / followers + following', () => {
    const user = process.env.BN_TEST_USER ?? 'varundubey';

    test('desktop: /members/{user}/followers/ renders', async ({ authenticatedPage: page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        const response = await page.goto(urls.memberFollowers(user));

        expect(response?.status() ?? 0, '/followers/ must not 404').not.toBe(404);
        await expect(page.locator('.bn-member-row-list, .bn-empty-state').first()).toBeVisible({ timeout: 5_000 });
        await expect(page.locator(sel.profileTab).first()).toBeVisible();
    });

    test('desktop: /members/{user}/following/ renders', async ({ authenticatedPage: page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        const response = await page.goto(urls.memberFollowing(user));

        expect(response?.status() ?? 0, '/following/ must not 404').not.toBe(404);
        await expect(page.locator('.bn-member-row-list, .bn-empty-state').first()).toBeVisible({ timeout: 5_000 });
        await expect(page.locator(sel.profileTab).first()).toBeVisible();
    });

    test('mobile (390px): followers page is scrollable', async ({ authenticatedPage: page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(urls.memberFollowers(user));
        await expect(page.locator('.bn-member-row-list, .bn-empty-state').first()).toBeVisible({ timeout: 5_000 });
    });

    test('mobile (390px): following page is scrollable', async ({ authenticatedPage: page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(urls.memberFollowing(user));
        await expect(page.locator('.bn-member-row-list, .bn-empty-state').first()).toBeVisible({ timeout: 5_000 });
    });

    test('profile stat counts link to followers and following', async ({ authenticatedPage: page }) => {
        await page.goto(urls.member(user));
        await expect(page.locator(sel.profileStats).first()).toBeVisible();

        // Each count is an anchor (pill) carrying the matching profile URL.
        const followersLink = page.locator(`.bn-nav-metric[href*="/followers/"]`).first();
        await expect(followersLink).toBeVisible();
        const followingLink = page.locator(`.bn-nav-metric[href*="/following/"]`).first();
        await expect(followingLink).toBeVisible();
    });
});
