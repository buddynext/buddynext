import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from '../_fixtures/precondition';
import { sel, urls } from '../_fixtures/selectors';
import { readRestNonce, postIdOfCard, deletePostRest } from '../_fixtures/feed-wave1.helpers';
import type { Page } from '@playwright/test';

/**
 * J-551 pin a comment (B3).
 *
 * Written from the moderator's promise: "a comment I pin stays pinned — after a
 * reload, for everyone." The coverage matrix had "Pin comment" MISSING. Pinning
 * is a moderator/admin capability (CommentService::can_pin_comment); the actor
 * varundubey is a site admin, so the pin control renders on their own post's
 * comments.
 *
 * Effect-based: pin the comment, then RELOAD the page and RE-OPEN the thread so
 * the comment list is re-fetched from the server (`GET /comments`, which sets
 * each node's `pinned` from the stored `bn_pinned_comment_*` option). The
 * reloaded card must still carry the pinned class + "Pinned" badge. A pin that
 * flips the DOM but never persists (POST /comments/{id}/pin silently failing)
 * comes back un-pinned after the reload and fails here. The host post is deleted
 * in `finally`, which also clears its comment + pin option.
 */
test.describe('feed / pin comment', () => {
    const submitComment = '[data-wp-on--click="actions.submitComment"]';
    const commentCard = '.bn-comment-card';
    const pinBtn = '.bn-comment__pin-btn';
    const pinnedClass = 'bn-comment-card--pinned';
    const pinnedBadge = '.bn-comment__pinned-badge';

    const openThread = async (page: Page, postText: string): Promise<void> => {
        const card = page.locator(sel.postCard).filter({ hasText: postText }).first();
        await expect(card).toBeVisible({ timeout: 10_000 });
        await card.locator(sel.postComment).first().click();
    };

    test('J-551 a pinned comment persists its pinned state after reload', async ({ authenticatedPage: page }, testInfo) => {
        let createdPostId = 0;
        let nonce = '';
        const stamp = Date.now().toString().slice(-6);
        const postBody = `j551 pin-host ${stamp}`;
        const commentBody = `j551 pin this comment ${stamp}`;

        try {
            // Seed an own post to host the comment.
            await page.goto(urls.feed);
            await expect(page.locator(sel.composer).first()).toBeVisible();
            nonce = await readRestNonce(page);
            await page.locator(sel.composerTextarea).first().fill(postBody);
            await page.locator(sel.composerSubmit).first().click();
            await expect(page.locator(sel.postCard).filter({ hasText: postBody }).first()).toBeVisible({ timeout: 10_000 });

            await page.goto(urls.feed);
            const host = page.locator(sel.postCard).filter({ hasText: postBody }).first();
            await expect(host).toBeVisible({ timeout: 10_000 });
            createdPostId = await postIdOfCard(page, postBody);
            expect(createdPostId).toBeGreaterThan(0);

            // Post a comment.
            await host.locator(sel.postComment).first().click();
            const input = host.locator(sel.commentInput).first();
            if (!(await input.isVisible().catch(() => false))) {
                softSkip(testInfo, 'Comment input not exposed — comments feature may be off.');
                return;
            }
            await input.fill(commentBody);
            await host.locator(submitComment).first().click();

            // Scoped to the host card + patient enough for comment-render lag under
            // full-suite load.
            const comment = host.locator(commentCard, { hasText: commentBody }).first();
            await expect(comment).toBeVisible({ timeout: 15_000 });
            const commentId = await comment.getAttribute('data-comment-id');
            expect(Number(commentId)).toBeGreaterThan(0);

            // The pin control is moderator/admin-only; on an own post the admin has it.
            const pin = comment.locator(pinBtn).first();
            if (!(await pin.isVisible().catch(() => false))) {
                softSkip(testInfo, 'Pin control absent — actor lacks pin capability on this post.');
                return;
            }
            await pin.click();
            // Optimistic flip.
            await expect(comment).toHaveClass(new RegExp(pinnedClass), { timeout: 8_000 });

            // Persistence: reload + re-open the thread; the server re-serves the
            // comment as pinned.
            await page.goto(urls.feed);
            await openThread(page, postBody);
            const reloaded = page.locator(`${commentCard}[data-comment-id="${commentId}"]`).first();
            await expect(reloaded).toBeVisible({ timeout: 8_000 });
            await expect(reloaded).toHaveClass(new RegExp(pinnedClass));
            await expect(reloaded.locator(pinnedBadge)).toBeVisible();
        } finally {
            await deletePostRest(page.request, nonce, createdPostId).catch(() => {});
        }
    });
});
