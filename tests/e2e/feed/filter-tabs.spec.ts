import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip, resolveOtherMemberSlug } from '../_fixtures/precondition';
import { sel, urls } from '../_fixtures/selectors';
import { openMemberSession, restPost, restGet, readRestNonce, type MemberSession } from '../_fixtures/feed-wave1.helpers';

type Feed = { items?: Array<{ id: number }> };
import { userId, resetPair } from '../_fixtures/wp';

/**
 * J-521 feed filter tabs change scope (B4).
 *
 * Written from the member's promise: "the For-you and Following tabs show
 * different feeds — Following only shows people I follow." The coverage matrix
 * had "Filter tabs" MISSING.
 *
 * Effect-based, deterministic scope proof (not a class flip): a public post is
 * seeded from an author the viewer does NOT follow. On ?filter=for-you the
 * discovery catch-all surfaces it; on ?filter=following (authors I follow only)
 * it MUST be absent. Both server-rendered pages are asserted, plus aria-current
 * tracking the active tab. A filter that renders the same set regardless of tab
 * fails the Following leg. Seeded post + follow state torn down in `finally`.
 *
 * Covers: cap-run-an-activity-feed-members-post-to
 * Roles: admin, member
 */
test.describe('feed / filter tabs', () => {
    const tab = (slug: string) => `.bn-feed-filter-tab[data-filter="${slug}"]`;

    test('J-521 Following excludes a non-followed author that For-you shows', async ({ authenticatedPage: page, browser }, testInfo) => {
        const selfLogin = process.env.BN_TEST_USER ?? 'varundubey';
        const authorSlug = await resolveOtherMemberSlug(page, selfLogin);
        if (!authorSlug) {
            softSkip(testInfo, 'No other member to author the discovery post.');
            return;
        }
        const meId = await userId(selfLogin);
        const authorId = await userId(authorSlug);
        expect(meId).toBeGreaterThan(0);
        expect(authorId).toBeGreaterThan(0);

        const stamp = Date.now().toString().slice(-6);
        const content = `New to the neighborhood and looking for a good bakery ${stamp}`;
        let viewer: MemberSession | null = null;
        let postId = 0;
        let nonce = '';

        try {
            // The viewer must NOT follow the author for the scope difference to hold.
            await resetPair(meId, authorId);

            // The seeded member is the VIEWER and the test account the author.
            // For you ranks a viewer's connections first, so on a site where the
            // test account has many of them a stranger's post is on a later page:
            // true to the product, and nothing to do with the tab scope under test.
            // The seeded member has no connections, so page one is by recency.
            await page.goto(urls.feed, { waitUntil: 'domcontentloaded' });
            nonce = await readRestNonce(page);
            const created = await restPost<{ id?: number }>(page.request, nonce, '/posts', {
                content,
                privacy: 'public',
            });
            expect(created.status, `seed post -> ${created.status}`).toBeLessThan(300);
            postId = created.body.id ?? 0;
            expect(postId).toBeGreaterThan(0);

            viewer = await openMemberSession(browser, authorSlug);

            // For-you (discovery) — the public post from a non-followed author shows.
            await viewer.page.goto(`${urls.feed}?filter=for-you`, { waitUntil: 'domcontentloaded' });
            await expect(viewer.page.locator(`${tab('for-you')}[aria-current="true"]`)).toHaveCount(1);
            await expect(viewer.page.locator(sel.postCard).filter({ hasText: content }).first()).toBeVisible({ timeout: 10_000 });

            // Following — scoped to authors I follow; the same post is absent.
            await viewer.page.goto(`${urls.feed}?filter=following`, { waitUntil: 'domcontentloaded' });
            await expect(viewer.page.locator(`${tab('following')}[aria-current="true"]`)).toHaveCount(1);
            await expect(viewer.page.locator(sel.postCard).filter({ hasText: content })).toHaveCount(0);

            // EFFECT: each tab is the home feed route under that filter. The route serves the
            // post to For you and withholds it from Following, and the Following tab shows
            // nothing the route does not serve.
            const served = async (filter: string): Promise<number[]> => {
                const r = await restGet<Feed>(viewer!.request, viewer!.nonce, `/feed/home?filter=${filter}&per_page=50`);
                expect(r.status, `GET /feed/home?filter=${filter}`).toBe(200);
                return (r.body.items ?? []).map((it) => Number(it.id));
            };
            expect(await served('for-you'), 'For you route serves the discovery post').toContain(postId);
            const following = await served('following');
            expect(following, 'Following route serves a non-followed author').not.toContain(postId);
            const onScreen = await viewer.page
                .locator('article.bn-post-card[data-post-id]')
                .evaluateAll((els) => els.map((e) => Number((e as HTMLElement).dataset.postId)));
            expect(onScreen.filter((id) => !following.includes(id)), 'Following tab cards the route does not serve').toEqual([]);
        } finally {
            if (postId) {
                await page.request
                    .delete(`/wp-json/buddynext/v1/posts/${postId}`, { headers: { 'X-WP-Nonce': nonce } })
                    .catch(() => undefined);
            }
            await resetPair(meId, authorId).catch(() => {});
            if (viewer) {
                await viewer.ctx.close().catch(() => {});
            }
        }
    });
});
