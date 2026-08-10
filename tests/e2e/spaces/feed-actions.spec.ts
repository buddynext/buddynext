import { test, expect } from '../_fixtures/auth.fixture';
import { createSpaceApi, deleteSpaceApi, bnApi } from '../_fixtures/spaces-rest';
import { wp } from '../_fixtures/wp';

/**
 * J-680..J-683 — Feed ACTIONS on a post in space context (C4), Wave-4 NEW,
 * effect-based.
 *
 * The Wave-2 in-space specs covered CREATING and PINNING a space post; these
 * cover ENGAGING with one — react, comment, share, report — through the exact
 * `buddynext/v1` endpoints the post-card store calls (post-card.js), each with
 * `object_type=post` bound to a post created inside a throwaway space. Every
 * assertion checks a server CONSEQUENCE (a count moved, the item is retrievable,
 * the report reached the queue), and each action's counterpart/read-back is
 * exercised so an optimistic no-op can't pass. Post + space torn down in
 * `finally`.
 */
test.describe('spaces / feed actions in space context (J-680..J-683)', () => {
    /** Create a throwaway open space with one post; returns ids + cleanup. */
    const seedSpacePost = async (page: import('@playwright/test').Page, label: string) => {
        const stamp = Date.now().toString().slice(-8);
        const space = await createSpaceApi(page, { name: `E2E ${label} ${stamp}`, type: 'open' });
        const create = await bnApi(page, 'POST', '/posts', {
            type: 'text',
            content: `${label} target ${stamp}`,
            space_id: space.id,
        });
        expect(create.status, `seed post failed: ${JSON.stringify(create.data)}`).toBe(201);
        const postId = Number((create.data as { id?: number }).id);
        expect(postId).toBeGreaterThan(0);
        return { space, postId, stamp };
    };

    const reactionCount = async (page: import('@playwright/test').Page, postId: number): Promise<number> => {
        const res = await bnApi(page, 'GET', `/reactions?object_type=post&object_id=${postId}`);
        expect(res.status, `get reactions failed: ${JSON.stringify(res.data)}`).toBe(200);
        return Number((res.data as { count?: number }).count ?? 0);
    };

    test('J-680 reacting to a space post changes the reaction count (and un-react reverts)', async ({
        authenticatedPage: page,
    }) => {
        const { space, postId } = await seedSpacePost(page, 'React');
        try {
            expect(await reactionCount(page, postId)).toBe(0);

            // React → count 1, viewer marked reacted.
            const on = await bnApi(page, 'POST', '/reactions/toggle', {
                object_type: 'post',
                object_id: postId,
                emoji: 'like',
            });
            expect(on.status, `react failed: ${JSON.stringify(on.data)}`).toBe(200);
            expect((on.data as { has_reacted?: boolean }).has_reacted).toBe(true);
            expect(Number((on.data as { count?: number }).count)).toBe(1);
            expect(await reactionCount(page, postId)).toBe(1);

            // Un-react (counterpart) → back to 0.
            const off = await bnApi(page, 'POST', '/reactions/toggle', {
                object_type: 'post',
                object_id: postId,
                emoji: 'like',
            });
            expect(off.status).toBe(200);
            expect((off.data as { has_reacted?: boolean }).has_reacted).toBe(false);
            expect(await reactionCount(page, postId)).toBe(0);
        } finally {
            await bnApi(page, 'DELETE', `/posts/${postId}`);
            await deleteSpaceApi(page, space.id);
        }
    });

    test('J-681 commenting on a space post appears in that post comment list', async ({
        authenticatedPage: page,
    }) => {
        const { space, postId, stamp } = await seedSpacePost(page, 'Comment');
        const body = `space comment ${stamp} ${Math.random().toString(36).slice(2, 8)}`;
        // Neutralise the per-user comment throttle (buddynext_comment_rate_limit,
        // 30/min) for the test window: it is anti-spam behaviour UNRELATED to the
        // effect under test (a comment appearing in the post's list), and back-to-
        // back reruns otherwise pile into one per-minute bucket and 429. Restored
        // in finally. `0` disables the throttle (see CommentService.php:107-108).
        let prevRate: string | null = null;
        try {
            prevRate = (await wp(['option', 'get', 'buddynext_comment_rate_limit'])).trim();
        } catch {
            prevRate = null; // option unset → coded default (30)
        }
        await wp(['option', 'update', 'buddynext_comment_rate_limit', '0']);
        try {
            const before = await bnApi(page, 'GET', `/comments?object_type=post&object_id=${postId}&per_page=50`);
            expect(before.status).toBe(200);
            const beforeTotal = Number((before.data as { total?: number }).total ?? 0);

            const post = await bnApi(page, 'POST', '/comments', {
                object_type: 'post',
                object_id: postId,
                content: body,
            });
            expect(post.status, `comment failed: ${JSON.stringify(post.data)}`).toBeGreaterThanOrEqual(200);
            expect(post.status).toBeLessThan(300);

            // EFFECT: the comment is retrievable on THAT post and the total grew.
            const after = await bnApi(page, 'GET', `/comments?object_type=post&object_id=${postId}&per_page=50`);
            expect(after.status).toBe(200);
            const items = (after.data as { items?: Array<{ content?: string }>; total?: number });
            expect(Number(items.total ?? 0)).toBe(beforeTotal + 1);
            expect(
                (items.items ?? []).some((c) => (c.content ?? '').includes(body)),
                'new comment not found in the post comment list'
            ).toBe(true);
        } finally {
            // Restore the throttle to exactly its prior state.
            if (prevRate !== null && prevRate !== '') {
                await wp(['option', 'update', 'buddynext_comment_rate_limit', prevRate]);
            } else {
                await wp(['option', 'delete', 'buddynext_comment_rate_limit']).catch(() => undefined);
            }
            await bnApi(page, 'DELETE', `/posts/${postId}`);
            await deleteSpaceApi(page, space.id);
        }
    });

    test('J-682 sharing a space post increments its share count', async ({
        authenticatedPage: page,
    }) => {
        const { space, postId, stamp } = await seedSpacePost(page, 'Share');
        let sharePostId = 0;
        try {
            const share = await bnApi(page, 'POST', `/posts/${postId}/share`, {
                content: `reshare ${stamp}`,
            });
            expect(share.status, `share failed: ${JSON.stringify(share.data)}`).toBe(200);
            expect((share.data as { shared?: boolean }).shared).toBe(true);
            sharePostId = Number((share.data as { post_id?: number }).post_id ?? 0);
            expect(sharePostId, 'share did not return a repost id').toBeGreaterThan(0);

            // EFFECT: the source post's share_count moved.
            const src = await bnApi(page, 'GET', `/posts/${postId}`);
            expect(src.status).toBe(200);
            expect(Number((src.data as { share_count?: number }).share_count ?? 0)).toBeGreaterThanOrEqual(1);
        } finally {
            if (sharePostId > 0) {
                await bnApi(page, 'DELETE', `/posts/${sharePostId}`);
            }
            await bnApi(page, 'DELETE', `/posts/${postId}`);
            await deleteSpaceApi(page, space.id);
        }
    });

    test('J-683 reporting a space post files it into the moderation queue', async ({
        authenticatedPage: page,
    }) => {
        const { space, postId } = await seedSpacePost(page, 'Report');
        let reportId = 0;
        try {
            const report = await bnApi(page, 'POST', '/reports', {
                object_type: 'post',
                object_id: postId,
                reason: 'spam',
                space_id: space.id,
            });
            expect(report.status, `report failed: ${JSON.stringify(report.data)}`).toBe(201);
            reportId = Number((report.data as { id?: number }).id);
            expect(reportId).toBeGreaterThan(0);

            // EFFECT: the report is retrievable in the moderation queue, keyed to
            // the reported post.
            const queue = await bnApi(page, 'GET', '/reports/queue?per_page=100&object_type=post');
            expect(queue.status).toBe(200);
            const items = (queue.data as { items?: Array<{ id: number; object_id: number }> }).items ?? [];
            expect(
                items.some((i) => Number(i.id) === reportId && Number(i.object_id) === postId),
                'reported space post not found in GET /reports/queue'
            ).toBe(true);
        } finally {
            if (reportId > 0) {
                await bnApi(page, 'POST', `/reports/${reportId}/dismiss`);
            }
            await bnApi(page, 'DELETE', `/posts/${postId}`);
            await deleteSpaceApi(page, space.id);
        }
    });
});
