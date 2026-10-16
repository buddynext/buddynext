import { test, expect } from '../_fixtures/auth.fixture';
import { createSpaceApi, deleteSpaceApi, bnApi, loginContextAs, type SpaceRow } from '../_fixtures/spaces-rest';
import { urls, sel } from '../_fixtures/selectors';
import { wp } from '../_fixtures/wp';

/**
 * J-693 — Search inside one space (C4).
 *
 * Covers: cap-search-inside-one-space
 * Roles: admin, member
 *
 * A space's Feed tab exposes a scoped search (`?bn_sf_q=` on the space's own
 * clean URL — SpaceNav::render_feed_panel()) that swaps the chronological list
 * for matches inside THAT space only (`scope_space_id` narrowing the search
 * index query). Two throwaway OPEN spaces are seeded: the target carries one
 * matching post and one non-matching post; a second (decoy) space carries a
 * post using the SAME search term — proving the scope narrows to the space in
 * the URL rather than matching site-wide (the exact leak the two-gate comment
 * in SpaceNav.php calls out). Indexing is dispatched through Action Scheduler
 * (`buddynext_async_index_post`); the queue is drained via wp-cli before
 * asserting so the check does not race it (same pattern as
 * feed/hashtag-post.spec.ts). Effect asserted at 390px: the matching post card
 * is the ONLY card carrying the term, the non-matching post is absent, and the
 * decoy space's post is absent. Both roles walk the same open space — its
 * content gate (SpaceVisibility::can_view_content) requires no membership, so
 * a non-owner member reaches the same scoped search without joining first.
 * Posts + spaces torn down in `finally`.
 */
test.describe('spaces / scoped search inside one space (J-693)', () => {
    const other = process.env.BN_TEST_OTHER_USER ?? 'alice';

    type Fixture = {
        target: SpaceRow;
        decoy: SpaceRow;
        matchId: number;
        otherId: number;
        decoyId: number;
        term: string;
    };

    /** Seed a target + decoy space with one matching / non-matching / decoy-matching post each. */
    const seed = async (page: import('@playwright/test').Page, label: string): Promise<Fixture> => {
        const stamp = Date.now().toString().slice(-8);
        const rand = Math.random().toString(36).slice(2, 8);
        const term = `zzsfterm${stamp}`;
        const target = await createSpaceApi(page, { name: `Lakeside Anglers ${stamp}`, type: 'open' });
        const decoy = await createSpaceApi(page, { name: `Mountain Climbers ${stamp}`, type: 'open' });

        const match = await bnApi(page, 'POST', '/posts', {
            type: 'text',
            content: `Best bait for early morning bass ${term}`,
            space_id: target.id,
        });
        expect(match.status, `seed matching post failed: ${JSON.stringify(match.data)}`).toBe(201);

        const otherPost = await bnApi(page, 'POST', '/posts', {
            type: 'text',
            content: `Anyone trading spare tackle boxes ${rand} ${stamp}`,
            space_id: target.id,
        });
        expect(otherPost.status, `seed non-matching post failed: ${JSON.stringify(otherPost.data)}`).toBe(201);

        const decoyPost = await bnApi(page, 'POST', '/posts', {
            type: 'text',
            content: `Chalk and rope sale this Friday ${term}`,
            space_id: decoy.id,
        });
        expect(decoyPost.status, `seed decoy post failed: ${JSON.stringify(decoyPost.data)}`).toBe(201);

        // Indexing runs via Action Scheduler (SearchIndexListener::dispatch); drain the
        // queue so the assertions below don't race it.
        await wp(['action-scheduler', 'run', '--group=buddynext']).catch(() => '');

        // EFFECT (fixture truth): each post sits in the space feed it was written to, so the
        // scoped search below is choosing between real rows, not passing on an empty decoy.
        const feedIds = async (spaceId: number): Promise<number[]> => {
            const res = await bnApi(page, 'GET', `/spaces/${spaceId}/feed?per_page=50`);
            expect(res.status, `space feed failed: ${JSON.stringify(res.data)}`).toBe(200);
            return ((res.data as { items?: Array<{ id: number }> }).items ?? []).map((i) => Number(i.id));
        };
        const matchPostId = Number((match.data as { id?: number }).id);
        const otherPostId = Number((otherPost.data as { id?: number }).id);
        const decoyPostId = Number((decoyPost.data as { id?: number }).id);
        expect((await feedIds(target.id)).sort()).toEqual([matchPostId, otherPostId].sort());
        expect(await feedIds(decoy.id)).toEqual([decoyPostId]);

        return {
            target,
            decoy,
            matchId: Number((match.data as { id?: number }).id),
            otherId: Number((otherPost.data as { id?: number }).id),
            decoyId: Number((decoyPost.data as { id?: number }).id),
            term,
        };
    };

    const cleanup = async (page: import('@playwright/test').Page, f: Fixture): Promise<void> => {
        await bnApi(page, 'DELETE', `/posts/${f.matchId}`).catch(() => undefined);
        await bnApi(page, 'DELETE', `/posts/${f.otherId}`).catch(() => undefined);
        await bnApi(page, 'DELETE', `/posts/${f.decoyId}`).catch(() => undefined);
        await deleteSpaceApi(page, f.target.id);
        await deleteSpaceApi(page, f.decoy.id);
    };

    /** Navigate a viewer's page to the target space's scoped search and assert the effect. */
    const assertScopedResults = async (
        page: import('@playwright/test').Page,
        f: Fixture,
        label: string
    ): Promise<void> => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(`${urls.space(f.target.slug)}?bn_sf_q=${encodeURIComponent(f.term)}`, {
            waitUntil: 'domcontentloaded',
        });

        const termCards = page.locator(sel.postCard).filter({ hasText: f.term });
        await expect(termCards.first(), `${label}: matching post not shown for scoped search`).toBeVisible({
            timeout: 10_000,
        });
        // EFFECT (scoping): exactly the target's own matching post — not also the
        // decoy space's post carrying the identical term.
        expect(await termCards.count(), `${label}: a post from a DIFFERENT space leaked into this search`).toBe(1);

        expect(
            await page.locator(sel.postCard).filter({ hasText: 'space search unrelated' }).count(),
            `${label}: non-matching post leaked into search results`
        ).toBe(0);
        // The result is the one post, nothing else rendered beside it.
        expect(await page.locator(sel.postCard).count(), `${label}: scoped search rendered extra cards`).toBe(1);

        // EFFECT: clearing the search brings the whole space feed back (the scope is a
        // filter over the feed, it did not replace it), still without the decoy.
        // The explicit /feed/ tab: a non-member's bare space URL lands on About by design.
        await page.goto(`${urls.space(f.target.slug)}feed/`, { waitUntil: 'domcontentloaded' });
        await expect(page.locator(sel.postCard).filter({ hasText: 'space search unrelated' }).first()).toBeVisible({ timeout: 10_000 });
        await expect(page.locator(sel.postCard).filter({ hasText: 'decoy other space' })).toHaveCount(0);
    };

    test('J-693 the space owner searching inside a space sees only matches from that space', async ({
        authenticatedPage: page,
    }) => {
        const f = await seed(page, 'Owner');
        try {
            await assertScopedResults(page, f, 'owner');
        } finally {
            await cleanup(page, f);
        }
    });

    test('J-693 member  -  a member searching inside an open space sees only matches from that space', async ({
        authenticatedPage: page,
        browser,
        baseURL,
    }) => {
        const f = await seed(page, 'Member');
        const actor = await loginContextAs(browser, baseURL, other);
        try {
            // An 'open' space's content gate does not require membership
            // (SpaceVisibility::can_view_content), so a non-owner member reaches the
            // same scoped search without joining first.
            await assertScopedResults(actor.page, f, 'member');
        } finally {
            await actor.ctx.close();
            await cleanup(page, f);
        }
    });
});
