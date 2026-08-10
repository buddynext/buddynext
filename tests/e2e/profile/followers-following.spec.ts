import { test, expect } from '../_fixtures/auth.fixture';
import { sel, urls } from '../_fixtures/selectors';
import { userId, ensureUser, setUserMeta, getUserMeta, tablePrefix, wp } from '../_fixtures/wp';
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

    /**
     * A3 Following grid — a real followee appears.
     *
     * UPGRADED from WEAK → EFFECT (Wave-4). The old body asserted only that the
     * following surface rendered "grid OR empty-state" — presence that is green on
     * an empty account and says nothing about whether a real followee lands in the
     * grid. It now SEEDS a real follow through the REST write path (A follows B via
     * POST /users/{B}/follow using A's own session + nonce), then asserts B's
     * member card appears in A's Following grid. The follow row is removed in a
     * finally so reruns start clean.
     */
    test('desktop: /members/{user}/following/ lists a real followee', async ({ authenticatedPage: page, browser }) => {
        await page.setViewportSize({ width: 1440, height: 900 });

        const p = await tablePrefix();
        const clearSql = `DELETE FROM ${p}bn_follows WHERE follower_id=${A_ID} AND following_id=${B_ID}`;
        await wp(['db', 'query', clearSql]);

        // Seed: A follows B through the real endpoint (not raw SQL — the row then
        // carries every canonical side effect the grid reads).
        const aSess = await openMemberSession(browser, A_LOGIN);
        try {
            const { status } = await restPost(aSess.request, aSess.nonce, `/users/${B_ID}/follow`, {});
            expect(status, `A must be able to follow B (got ${status})`).toBeLessThan(300);

            const response = await page.goto(urls.memberFollowing(user));
            expect(response?.status() ?? 0, '/following/ must not 404').not.toBe(404);
            await expect(page.locator(sel.profileTab).first()).toBeVisible();

            // Effect: B's member card is in A's Following grid (server truth).
            await expect(
                page.locator(`.bn-pf-tab-content a[href*="/members/${B_LOGIN}"]`).first(),
                'the seeded followee must appear in the Following grid'
            ).toBeVisible({ timeout: 5_000 });
        } finally {
            await wp(['db', 'query', clearSql]);
            await aSess.ctx.close();
        }
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

    /**
     * A3 stat counts reflect real numbers.
     *
     * UPGRADED from WEAK → EFFECT (Wave-4). The old body only asserted the two
     * count pills were VISIBLE anchors — it never checked the number they show, so
     * it passed even if the hero rendered "0 Followers" while the store held ten.
     *
     * The followers pill is fed by the denormalised `bn_follower_count` counter
     * (FollowService::follower_count → usermeta + object cache), NOT a live
     * COUNT(*), so the effect is asserted against that source of truth: the pill
     * equals the stored counter, and a REAL follow through the write path
     * increments BOTH the counter AND the pill by exactly one (then an unfollow
     * puts both back). Every follow/unfollow goes through REST so the denormalised
     * counter stays consistent; the account is left exactly as found.
     */
    test('profile stat counts reflect real follower numbers', async ({ authenticatedPage: page, browser }) => {
        const followerMeta = async (): Promise<number> => {
            const v = await getUserMeta(A_ID, 'bn_follower_count');
            return parseInt((v || '0').replace(/[^\d-]/g, ''), 10) || 0;
        };
        const pillValue = async (): Promise<number> => {
            await page.goto(urls.member(user));
            await expect(page.locator(sel.profileStats).first()).toBeVisible();
            const link = page.locator(`.bn-nav-metric[href*="/followers/"]`).first();
            await expect(link, 'the followers count pill is a link to the followers list').toBeVisible();
            const txt = await link.locator('.bn-nav-metric__value').innerText();
            return parseInt(txt.replace(/[^\d-]/g, ''), 10) || 0;
        };

        const bSess = await openMemberSession(browser, B_LOGIN);
        try {
            // Start from a known state: B is not following A (idempotent, via REST so
            // the denormalised counter stays consistent).
            await bSess.request
                .delete(`/wp-json/buddynext/v1/users/${A_ID}/follow`, { headers: { 'X-WP-Nonce': bSess.nonce } })
                .catch(() => undefined);

            // The displayed count matches the stored counter (a real number, not just
            // "an anchor exists").
            const before = await followerMeta();
            expect(await pillValue(), 'the followers pill must show the stored follower counter').toBe(before);

            // A real follow increments the counter AND the pill by exactly one.
            const { status } = await restPost(bSess.request, bSess.nonce, `/users/${A_ID}/follow`, {});
            expect(status, `B must be able to follow A (got ${status})`).toBeLessThan(300);
            expect(await followerMeta(), 'the follower counter rose by one').toBe(before + 1);
            await expect
                .poll(async () => await pillValue(), { timeout: 8_000 })
                .toBe(before + 1);

            // Unfollow (via REST) returns both the counter and the pill to baseline.
            await bSess.request
                .delete(`/wp-json/buddynext/v1/users/${A_ID}/follow`, { headers: { 'X-WP-Nonce': bSess.nonce } });
            expect(await followerMeta(), 'the follower counter returned to baseline').toBe(before);
            await expect.poll(async () => await pillValue(), { timeout: 8_000 }).toBe(before);
        } finally {
            // Leave A as found: ensure B is not following A (via REST → counter stays true).
            await bSess.request
                .delete(`/wp-json/buddynext/v1/users/${A_ID}/follow`, { headers: { 'X-WP-Nonce': bSess.nonce } })
                .catch(() => undefined);
            await bSess.ctx.close();
        }
    });
});
