import { test, expect } from '../_fixtures/auth.fixture';
import { sel } from '../_fixtures/selectors';
import { createSpaceApi, deleteSpaceApi, bnApi } from '../_fixtures/spaces-rest';
import { displayName, userId } from '../_fixtures/wp';

/**
 * J-42 space home + J-43 post in space + J-44 member list — UPGRADED to
 * effect-based (Wave-4).
 *
 * Covers: cap-run-an-activity-feed-members-post-to, cap-group-content-into-spaces
 * Roles: admin
 *
 * The Wave-1 versions asserted only that "the hero OR the space name" appeared,
 * that the composer either posted OR simply cleared (a swallow), and that "a"
 * member card was visible on a hard-coded env space. Each now creates its own
 * throwaway space, seeds a deterministic precondition, and asserts a real
 * consequence:
 *   - J-42: the seeded space's exact name renders in the hero AND a REST-seeded
 *     first post is present as a server-rendered card in the space feed.
 *   - J-43: composing through the real `.bn-composer` UI persists — the post is
 *     retrievable in `GET /spaces/{id}/feed` and paints as a card after reload.
 *   - J-44: a KNOWN member (the owner, by display name) is present in the members
 *     grid, not merely "some card".
 *
 * The space feed is SERVER-rendered (SpaceNav::render_feed_panel → the SSR loop
 * in parts/space-feed-panel.php), so a REST-created post appears as a
 * `.bn-post-card` on reload — no client hydration race. Space tabs use CLEAN
 * URLs (`/spaces/{slug}/{tab}/`), not `?bn_tab=`. Throwaway space + any seeded
 * post are torn down in `finally`.
 */
test.describe('spaces / home', () => {
    // Live hero title (space-hero.php:140); the members page (dedicated
    // spaces/members.php) renders member-directory cards (`.bn-md-card__name`).
    const HERO_NAME = '.bn-sh-hero__name';
    const MEMBER_NAME = '.bn-md-card__name';

    type FeedItem = { id: number; content?: string };

    const spaceFeedItems = async (
        page: import('@playwright/test').Page,
        spaceId: number
    ): Promise<FeedItem[]> => {
        const res = await bnApi(page, 'GET', `/spaces/${spaceId}/feed?per_page=50`);
        expect(res.status, `space feed failed: ${JSON.stringify(res.data)}`).toBe(200);
        return ((res.data as { items?: FeedItem[] }).items ?? []) as FeedItem[];
    };

    test('J-42 space home renders the seeded name + first post', async ({ authenticatedPage: page }) => {
        const stamp = Date.now().toString().slice(-8);
        const name = `Coastal Cleanup Volunteers ${stamp}`;
        const first = `Meeting at the pier at nine, gloves provided ${stamp} ${Math.random().toString(36).slice(2, 8)}`;
        const space = await createSpaceApi(page, { name, type: 'open' });
        let postId = 0;

        try {
            const created = await bnApi(page, 'POST', '/posts', {
                type: 'text',
                content: first,
                space_id: space.id,
            });
            expect(created.status, `seed post failed: ${JSON.stringify(created.data)}`).toBe(201);
            postId = Number((created.data as { id?: number }).id);
            expect(postId).toBeGreaterThan(0);

            await page.goto(`/spaces/${space.slug}/`, { waitUntil: 'domcontentloaded' });
            await expect(page.locator(sel.app)).toBeVisible();

            // EFFECT 1: the hero paints THIS space's name.
            await expect(page.locator(HERO_NAME).first()).toContainText(name, { timeout: 10_000 });

            // EFFECT 2: the seeded first post is server-rendered in the feed.
            const card = page.locator(sel.postCard).filter({ hasText: first }).first();
            await expect(card, 'seeded first post did not render in the space feed').toBeVisible({
                timeout: 10_000,
            });

            // EFFECT: the feed on screen is the space feed route's answer: this one post,
            // and one card.
            const items = await spaceFeedItems(page, space.id);
            expect(items.map((i) => Number(i.id))).toEqual([postId]);
            expect(await page.locator(sel.postCard).count(), 'cards on screen vs GET /spaces/{id}/feed').toBe(items.length);
        } finally {
            if (postId > 0) {
                await bnApi(page, 'DELETE', `/posts/${postId}`);
            }
            await deleteSpaceApi(page, space.id);
        }
    });

    test('J-43 composing in a space persists to the space feed', async ({ authenticatedPage: page }) => {
        const stamp = Date.now().toString().slice(-8);
        const space = await createSpaceApi(page, { name: `Local Bookworms ${stamp}`, type: 'open' });
        const content = `Just finished the new Le Guin, thoughts? ${stamp} ${Math.random().toString(36).slice(2, 8)}`;
        let postId = 0;

        try {
            await page.goto(`/spaces/${space.slug}/`, { waitUntil: 'domcontentloaded' });

            // The owner (admin) is an active member, so the composer renders.
            const composer = page.locator(sel.composer).first();
            await expect(composer, 'in-space composer not visible to the owner').toBeVisible({
                timeout: 10_000,
            });
            await page.locator(sel.composerTextarea).first().fill(content);
            // The composer reloads the page ~500ms after a live post saves (cards
            // added after first paint cannot hydrate; see prependFeedCard()), so
            // wait that reload out before polling through the page, or the poll's
            // evaluate dies with "Execution context was destroyed".
            // Wait for the main frame to commit that reload, then for it to load (a bare
            // 'load' can resolve first, and the goto below then collided with the reload on iPad).
            const reloaded = page
                .waitForEvent('framenavigated', { predicate: (f) => f === page.mainFrame(), timeout: 10_000 })
                .then(() => page.waitForLoadState('load'))
                .catch(() => null);
            await page.locator(sel.composerSubmit).first().click();
            await reloaded;

            // EFFECT 1: the post actually reached the server and is bound to this
            // space's feed (not swallowed). Poll — the Interactivity submit is async.
            await expect
                .poll(
                    async () => {
                        const items = await spaceFeedItems(page, space.id);
                        const hit = items.find((i) => (i.content ?? '').includes(content));
                        if (hit) {
                            postId = Number(hit.id);
                        }
                        return Boolean(hit);
                    },
                    { timeout: 15_000, message: 'composed post never appeared in GET /spaces/{id}/feed' }
                )
                .toBe(true);

            // EFFECT 2: it paints as a real card after a reload (SSR feed).
            await page.goto(`/spaces/${space.slug}/`, { waitUntil: 'domcontentloaded' });
            await expect(
                page.locator(sel.postCard).filter({ hasText: content }).first()
            ).toBeVisible({ timeout: 10_000 });
        } finally {
            if (postId > 0) {
                await bnApi(page, 'DELETE', `/posts/${postId}`);
            }
            await deleteSpaceApi(page, space.id);
        }
    });

    test('J-44 member list shows a known member', async ({ authenticatedPage: page }) => {
        const stamp = Date.now().toString().slice(-8);
        const space = await createSpaceApi(page, { name: `Parents of Toddlers ${stamp}`, type: 'open' });

        try {
            // The owner is always an active member; resolve their canonical
            // display name so the assertion targets a KNOWN person, not "a card".
            const ownerLogin = process.env.BN_TEST_USER ?? 'varundubey';
            const ownerName = await displayName(await userId(ownerLogin));
            expect(ownerName, 'could not resolve owner display name').toBeTruthy();

            await page.goto(`/spaces/${space.slug}/members/`, { waitUntil: 'domcontentloaded' });

            const named = page.locator(MEMBER_NAME).filter({ hasText: ownerName }).first();
            await expect(
                named,
                `members grid did not list the known owner "${ownerName}"`
            ).toBeVisible({ timeout: 10_000 });

            // EFFECT: the grid is the roster route's answer: the owner, alone, as owner.
            const roster = await bnApi(page, 'GET', `/spaces/${space.id}/members?per_page=100`);
            expect(roster.status, `roster failed: ${JSON.stringify(roster.data)}`).toBe(200);
            const rows = (roster.data as Array<{ user_id: number; role?: string; display_name?: string }>) ?? [];
            expect(rows.length).toBe(1);
            expect(rows[0].role).toBe('owner');
            expect(String(rows[0].display_name ?? '')).toBe(ownerName);
            expect(await page.locator(MEMBER_NAME).count(), 'member cards on screen vs roster rows').toBe(rows.length);
        } finally {
            await deleteSpaceApi(page, space.id);
        }
    });
});
