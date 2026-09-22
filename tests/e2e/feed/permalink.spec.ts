import { test, expect } from '../_fixtures/auth.fixture';
import { loginAs } from '../_fixtures/actor';
import { sel, urls } from '../_fixtures/selectors';
import { readRestNonce, restPost, deletePostRest } from '../_fixtures/feed-wave1.helpers';

const MEMBER_LOGIN = process.env.BN_TEST_OTHER_USER ?? 'alice';

/**
 * J-520 single-post permalink (B4).
 *
 * Written from the member's promise: "a post's permalink opens exactly that
 * post on its own page." The coverage matrix had "Single-post permalink"
 * MISSING.
 *
 * Effect-based, discriminating: two distinct posts A and B are seeded, then each
 * permalink is opened and asserted to render ITS post and NOT the other. A
 * permalink route that simply re-renders the whole feed (both posts present)
 * would pass a naive "post visible" check but fails this cross-check. Both posts
 * deleted in `finally`.
 *
 * Covers: cap-open-a-post-permalink-with-its-replies-visible
 * Roles: admin, member
 */
test.describe('feed / single-post permalink', () => {
    test('J-520 each permalink renders its own post standalone', async ({ authenticatedPage: page }) => {
        const stamp = Date.now().toString().slice(-6);
        const textA = `j520 alpha ${stamp}`;
        const textB = `j520 bravo ${stamp}`;
        let idA = 0;
        let idB = 0;
        let nonce = '';

        try {
            await page.goto(urls.feed);
            await expect(page.locator(sel.composer).first()).toBeVisible();
            nonce = await readRestNonce(page);

            const a = await restPost<{ id?: number }>(page.request, nonce, '/posts', { content: textA, privacy: 'public' });
            const b = await restPost<{ id?: number }>(page.request, nonce, '/posts', { content: textB, privacy: 'public' });
            idA = a.body.id ?? 0;
            idB = b.body.id ?? 0;
            expect(idA).toBeGreaterThan(0);
            expect(idB).toBeGreaterThan(0);

            // Permalink A shows A, not B.
            await page.goto(`/p/${idA}/`, { waitUntil: 'domcontentloaded' });
            await expect(page.locator(sel.postCard).filter({ hasText: textA }).first()).toBeVisible({ timeout: 10_000 });
            await expect(page.locator(sel.postCard).filter({ hasText: textB })).toHaveCount(0);

            // Permalink B shows B, not A.
            await page.goto(`/p/${idB}/`, { waitUntil: 'domcontentloaded' });
            await expect(page.locator(sel.postCard).filter({ hasText: textB }).first()).toBeVisible({ timeout: 10_000 });
            await expect(page.locator(sel.postCard).filter({ hasText: textA })).toHaveCount(0);
        } finally {
            await deletePostRest(page.request, nonce, idA).catch(() => {});
            await deletePostRest(page.request, nonce, idB).catch(() => {});
        }
    });

    /**
     * Member leg. The capability promise is literally "…with its replies
     * visible" — the admin walk above never seeded a reply. A member opens the
     * permalink of a post that has a comment and must see BOTH the post and its
     * reply on that standalone page, not the bare post.
     */
    test('J-520 member  -  a member opens a post permalink and its reply is visible', async ({ page }) => {
        await loginAs(page, MEMBER_LOGIN);
        const stamp = Date.now().toString().slice(-6);
        const postText = `j520m member host ${stamp}`;
        const replyText = `j520m member reply ${stamp}`;
        let postId = 0;
        let nonce = '';

        try {
            await page.goto(urls.feed);
            await expect(page.locator(sel.composer).first()).toBeVisible();
            nonce = await readRestNonce(page);

            const post = await restPost<{ id?: number }>(page.request, nonce, '/posts', { content: postText, privacy: 'public' });
            postId = post.body.id ?? 0;
            expect(postId).toBeGreaterThan(0);

            const comment = await restPost<{ id?: number }>(page.request, nonce, '/comments', {
                object_type: 'post',
                object_id: postId,
                content: replyText,
            });
            expect(comment.status, `seed reply -> ${comment.status}`).toBeLessThan(300);

            await page.goto(`/p/${postId}/`, { waitUntil: 'domcontentloaded' });
            await expect(page.locator(sel.postCard).filter({ hasText: postText }).first()).toBeVisible({ timeout: 10_000 });
            await expect(page.locator('.bn-comment-card', { hasText: replyText }).first()).toBeVisible({ timeout: 10_000 });
        } finally {
            await deletePostRest(page.request, nonce, postId).catch(() => {});
        }
    });
});
