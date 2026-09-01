import { test, expect } from '../_fixtures/auth.fixture';
import { createSpaceApi, deleteSpaceApi, bnApi } from '../_fixtures/spaces-rest';

/**
 * J-620 / J-621 — In-space posting (C4).
 *
 * Wave-2 effect specs for the SpacePostGuard write path. The Wave-1 matrix left
 * "Post in space" as a pure `.catch(() => {})` swallow (passes even if the card
 * never appears).
 *
 * Every action here is performed against the REAL `buddynext/v1` endpoint the
 * front-end store calls, and every assertion checks a server CONSEQUENCE:
 *   - a post created with space_id lands in THAT space's feed carrying the space id;
 *   - a space post CANNOT be pinned — pinning is profile-only; spaces feature
 *     content through Announcements, so the pin endpoint refuses a space post.
 * A throwaway open space is created and deleted in `finally`, so reruns are clean.
 */
test.describe('spaces / in-space post + pin (J-620/J-621)', () => {
    type PostRow = { id?: number; space_id?: number; [k: string]: unknown };
    type FeedItem = { id: number; space_id?: number };

    const spaceFeedIds = async (
        page: import('@playwright/test').Page,
        spaceId: number
    ): Promise<number[]> => {
        const res = await bnApi(page, 'GET', `/spaces/${spaceId}/feed?per_page=50`);
        expect(res.status, `space feed failed: ${JSON.stringify(res.data)}`).toBe(200);
        const items = (res.data as { items?: FeedItem[] }).items ?? [];
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

    test('J-621 a space post cannot be pinned — pinning is profile-only', async ({
        authenticatedPage: page,
    }) => {
        const stamp = Date.now().toString().slice(-8);
        const space = await createSpaceApi(page, { name: `E2E Pin ${stamp}`, type: 'open' });
        let postId = 0;

        try {
            const create = await bnApi(page, 'POST', '/posts', {
                type: 'text',
                content: `no pin ${stamp}`,
                space_id: space.id,
            });
            expect(create.status, `create failed: ${JSON.stringify(create.data)}`).toBe(201);
            postId = Number((create.data as PostRow).id);
            expect(postId).toBeGreaterThan(0);

            // EFFECT: pinning a post that lives in a space is refused. Spaces
            // surface important content through Announcements, not pins.
            const pin = await bnApi(page, 'POST', `/posts/${postId}/pin`);
            expect(pin.status, `expected a rejection: ${JSON.stringify(pin.data)}`).toBe(403);
            expect((pin.data as { code?: string }).code).toBe('pin_not_allowed_in_space');
        } finally {
            if (postId > 0) {
                await bnApi(page, 'DELETE', `/posts/${postId}`);
            }
            await deleteSpaceApi(page, space.id);
        }
    });
});
