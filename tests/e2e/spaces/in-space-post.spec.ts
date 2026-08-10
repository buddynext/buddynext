import { test, expect } from '../_fixtures/auth.fixture';
import { createSpaceApi, deleteSpaceApi, bnApi } from '../_fixtures/spaces-rest';

/**
 * J-620 / J-621 — In-space posting (C4).
 *
 * Wave-2 effect specs for the SpacePostGuard write path and the space pinned
 * strip. The Wave-1 matrix left "Post in space" as a pure `.catch(() => {})`
 * swallow (passes even if the card never appears) and pin/unpin uncovered.
 *
 * Every action here is performed against the REAL `buddynext/v1` endpoint the
 * front-end store calls, and every assertion checks a server CONSEQUENCE:
 *   - a post created with space_id lands in THAT space's feed carrying the space id;
 *   - pinning surfaces the post in GET /spaces/{id}/pinned, unpinning removes it.
 * A throwaway open space is created and deleted in `finally`, so reruns are clean.
 */
test.describe('spaces / in-space post + pin (J-620/J-621)', () => {
    type PostRow = { id?: number; space_id?: number; [k: string]: unknown };
    type FeedItem = { id: number; space_id?: number };
    type PinnedItem = { id: number };

    const spaceFeedIds = async (
        page: import('@playwright/test').Page,
        spaceId: number
    ): Promise<number[]> => {
        const res = await bnApi(page, 'GET', `/spaces/${spaceId}/feed?per_page=50`);
        expect(res.status, `space feed failed: ${JSON.stringify(res.data)}`).toBe(200);
        const items = (res.data as { items?: FeedItem[] }).items ?? [];
        return items.map((i) => Number(i.id));
    };

    const pinnedIds = async (
        page: import('@playwright/test').Page,
        spaceId: number
    ): Promise<number[]> => {
        const res = await bnApi(page, 'GET', `/spaces/${spaceId}/pinned`);
        expect(res.status, `pinned list failed: ${JSON.stringify(res.data)}`).toBe(200);
        const items = (res.data as { pinned?: PinnedItem[] }).pinned ?? [];
        return items.map((i) => Number(i.id));
    };

    test('J-620 a post created in a space appears in that space feed with its space id', async ({
        authenticatedPage: page,
    }) => {
        const stamp = Date.now().toString().slice(-8);
        const space = await createSpaceApi(page, { name: `E2E InPost ${stamp}`, type: 'open' });
        const body = `space post ${stamp} ${Math.random().toString(36).slice(2, 8)}`;
        let postId = 0;

        try {
            const res = await bnApi(page, 'POST', '/posts', {
                type: 'text',
                content: body,
                space_id: space.id,
            });
            expect(res.status, `create in-space post failed: ${JSON.stringify(res.data)}`).toBe(201);
            const row = res.data as PostRow;
            postId = Number(row.id);
            expect(postId, 'created post carried no id').toBeGreaterThan(0);

            // EFFECT 1: the post is bound to the target space (not the global feed).
            expect(Number(row.space_id)).toBe(space.id);

            // EFFECT 2: the post is retrievable in that space's feed.
            expect(await spaceFeedIds(page, space.id)).toContain(postId);
        } finally {
            if (postId > 0) {
                await bnApi(page, 'DELETE', `/posts/${postId}`);
            }
            await deleteSpaceApi(page, space.id);
        }
    });

    test('J-621 pinning a space post surfaces it in the pinned strip; unpin removes it', async ({
        authenticatedPage: page,
    }) => {
        const stamp = Date.now().toString().slice(-8);
        const space = await createSpaceApi(page, { name: `E2E Pin ${stamp}`, type: 'open' });
        let postId = 0;

        try {
            const create = await bnApi(page, 'POST', '/posts', {
                type: 'text',
                content: `pin me ${stamp}`,
                space_id: space.id,
            });
            expect(create.status, `create failed: ${JSON.stringify(create.data)}`).toBe(201);
            postId = Number((create.data as PostRow).id);
            expect(postId).toBeGreaterThan(0);

            // Baseline: not pinned yet.
            expect(await pinnedIds(page, space.id)).not.toContain(postId);

            // ── PIN ──────────────────────────────────────────────────────────
            const pin = await bnApi(page, 'POST', `/posts/${postId}/pin`);
            expect(pin.status, `pin failed: ${JSON.stringify(pin.data)}`).toBe(200);
            expect((pin.data as { pinned?: boolean }).pinned).toBe(true);

            // EFFECT: appears in the space pinned strip.
            expect(await pinnedIds(page, space.id)).toContain(postId);

            // ── UNPIN (counterpart) ────────────────────────────────────────────
            const unpin = await bnApi(page, 'DELETE', `/posts/${postId}/pin`);
            expect(unpin.status, `unpin failed: ${JSON.stringify(unpin.data)}`).toBe(200);
            expect((unpin.data as { pinned?: boolean }).pinned).toBe(false);

            // EFFECT: gone from the pinned strip.
            expect(await pinnedIds(page, space.id)).not.toContain(postId);
        } finally {
            if (postId > 0) {
                await bnApi(page, 'DELETE', `/posts/${postId}`);
            }
            await deleteSpaceApi(page, space.id);
        }
    });
});
