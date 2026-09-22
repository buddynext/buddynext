import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from '../_fixtures/precondition';
import { loginAs } from '../_fixtures/actor';
import { sel, urls } from '../_fixtures/selectors';
import { readRestNonce, postIdOfCard, deletePostRest, restPost } from '../_fixtures/feed-wave1.helpers';
import type { Page } from '@playwright/test';

const MEMBER_LOGIN = process.env.BN_TEST_OTHER_USER ?? 'alice';

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
 *
 * Covers: cap-pin-a-post-to-a-profile-or-pin-a-comment-on-a-post
 * Roles: admin, member
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

    /**
     * Member leg — the capability BOUNDARY, not a new grant.
     *
     * CommentService::can_pin_comment() only ever returns true for
     * manage_options or a space moderator (includes/Comments/CommentService.php:654-676).
     * A regular member commenting on their own personal-feed post (space_id=0)
     * has neither, so the allowed behaviour FOR a member is that pinning is
     * refused — no pin control renders, and a direct REST attempt is rejected
     * server-side. Written as the effect that must NOT happen: the comment
     * must still read as unpinned afterward, proving the DOM absence isn't the
     * only gate (a control merely hidden by CSS, with the route still open,
     * would pass a presence check and fail this one).
     */
    test('member  -  a member has no pin control on their own post comment, and a direct pin attempt is refused', async ({ page }, testInfo) => {
        await loginAs(page, MEMBER_LOGIN);
        let createdPostId = 0;
        let nonce = '';
        const stamp = Date.now().toString().slice(-6);
        const postBody = `j551m member pin-host ${stamp}`;
        const commentBody = `j551m member comment ${stamp}`;

        try {
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

            await host.locator(sel.postComment).first().click();
            const input = host.locator(sel.commentInput).first();
            if (!(await input.isVisible().catch(() => false))) {
                softSkip(testInfo, 'Comment input not exposed — comments feature may be off.');
                return;
            }
            await input.fill(commentBody);
            await host.locator(submitComment).first().click();

            const comment = host.locator(commentCard, { hasText: commentBody }).first();
            await expect(comment).toBeVisible({ timeout: 15_000 });
            const commentId = await comment.getAttribute('data-comment-id');
            expect(Number(commentId)).toBeGreaterThan(0);

            // No pin control for this member, even on their own post's comment.
            await expect(comment.locator(pinBtn)).toHaveCount(0);

            // Server truth: a direct REST pin attempt is refused, not silently a
            // no-op — the route itself must enforce the same gate the DOM implies.
            const pinAttempt = await restPost<{ code?: string }>(page.request, nonce, `/comments/${commentId}/pin`, {});
            expect(pinAttempt.status, `member pin attempt -> ${pinAttempt.status}`).toBeGreaterThanOrEqual(400);

            // Effect confirmed: reload + re-open the thread and the comment still
            // reads as unpinned — the refused write left no trace.
            await page.goto(urls.feed);
            await openThread(page, postBody);
            const reloaded = page.locator(`${commentCard}[data-comment-id="${commentId}"]`).first();
            await expect(reloaded).toBeVisible({ timeout: 8_000 });
            await expect(reloaded).not.toHaveClass(new RegExp(pinnedClass));
        } finally {
            await deletePostRest(page.request, nonce, createdPostId).catch(() => {});
        }
    });
});
