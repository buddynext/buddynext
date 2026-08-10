import { test, expect } from '../_fixtures/auth.fixture';
import { sel, urls } from '../_fixtures/selectors';
import { userId, ensureUser, setUserMeta, tablePrefix, wp } from '../_fixtures/wp';
import { openMemberSession, restPost } from '../_fixtures/feed-wave1.helpers';

const A_LOGIN = process.env.BN_TEST_USER ?? 'varundubey';
const B_LOGIN = process.env.BN_TEST_OTHER_USER ?? 'bn_e2e_target';
const B_EMAIL = 'bn_e2e_target@example.com';
const B_NAME = 'BN E2E Target';

let A_ID = 0;
let B_ID = 0;

test.beforeAll(async () => {
    A_ID = await userId(A_LOGIN);
    B_ID = await ensureUser(B_LOGIN, B_EMAIL, B_NAME);
    expect(A_ID, `actor "${A_LOGIN}" must exist`).toBeGreaterThan(0);
    expect(B_ID, `follower "${B_LOGIN}" must exist`).toBeGreaterThan(0);
    await setUserMeta(B_ID, 'bn_onboarding_complete', '1');
});

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
/**
 * The list surface INSIDE the profile tab.
 *
 * These assertions used to read `.bn-member-row-list, .bn-empty-state`, which is
 * wrong for this page: the tab renders the member-directory grid
 * (`.bn-md-grid` of `.bn-md-card`), while `.bn-member-row-list` belongs to the
 * SIDEBAR widget. Playwright's `.first()` on a comma selector returns the first
 * match in DOM ORDER, so every assertion resolved to the sidebar.
 *
 * At desktop that sidebar is visible, so two of these tests passed while
 * checking the wrong element entirely. At 390px the sidebar is hidden - measured
 * 0x0, offsetParent null - so the same assertion failed and reported as a broken
 * mobile layout. The following list was rendering correctly the whole time, 366
 * x 1261 at 390px.
 *
 * Scoping to `.bn-pf-tab-content` makes all four check the surface they name.
 */
const TAB_SURFACE = '.bn-pf-tab-content .bn-md-grid, .bn-pf-tab-content .bn-empty-state';

test.describe('profile / followers + following', () => {
    const user = process.env.BN_TEST_USER ?? 'varundubey';

    /**
     * J-123 an actual follower appears in the Followers grid.
     *
     * UPGRADED from WEAK → EFFECT (Wave-3). The old body asserted only that the
     * followers surface rendered "grid OR empty-state" — presence that passed on
     * an empty account and told nothing about whether a real follow ever lands in
     * the grid. It now SEEDS a real follow through the REST write path (member B
     * follows A via POST /users/{A}/follow, using B's own session + nonce), then
     * asserts B's card appears in A's Followers grid. The follow row is removed in
     * a finally so reruns start clean.
     */
    test('desktop: /members/{user}/followers/ lists a real follower', async ({ authenticatedPage: page, browser }) => {
        await page.setViewportSize({ width: 1440, height: 900 });

        const p = await tablePrefix();
        const clearSql = `DELETE FROM ${p}bn_follows WHERE follower_id=${B_ID} AND following_id=${A_ID}`;
        await wp(['db', 'query', clearSql]);

        // Seed: B follows A through the real endpoint (not raw SQL — the row then
        // carries every canonical side effect the grid reads).
        const bSess = await openMemberSession(browser, B_LOGIN);
        try {
            const { status } = await restPost(bSess.request, bSess.nonce, `/users/${A_ID}/follow`, {});
            expect(status, `B must be able to follow A (got ${status})`).toBeLessThan(300);

            const response = await page.goto(urls.memberFollowers(user));
            expect(response?.status() ?? 0, '/followers/ must not 404').not.toBe(404);
            await expect(page.locator(sel.profileTab).first()).toBeVisible();

            // Effect: B's member card is in A's Followers grid (server truth).
            await expect(
                page.locator(`.bn-pf-tab-content a[href*="/members/${B_LOGIN}"]`).first(),
                'the seeded follower must appear in the Followers grid'
            ).toBeVisible({ timeout: 5_000 });
        } finally {
            await wp(['db', 'query', clearSql]);
            await bSess.ctx.close();
        }
    });

    test('desktop: /members/{user}/following/ renders', async ({ authenticatedPage: page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        const response = await page.goto(urls.memberFollowing(user));

        expect(response?.status() ?? 0, '/following/ must not 404').not.toBe(404);
        await expect(page.locator(TAB_SURFACE).first()).toBeVisible({ timeout: 5_000 });
        await expect(page.locator(sel.profileTab).first()).toBeVisible();
    });

    test('mobile (390px): followers page is scrollable', async ({ authenticatedPage: page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(urls.memberFollowers(user));
        await expect(page.locator(TAB_SURFACE).first()).toBeVisible({ timeout: 5_000 });
    });

    test('mobile (390px): following page is scrollable', async ({ authenticatedPage: page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(urls.memberFollowing(user));
        await expect(page.locator(TAB_SURFACE).first()).toBeVisible({ timeout: 5_000 });
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
