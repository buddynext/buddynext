import { test, expect } from '../_fixtures/auth.fixture';
import { sel, urls } from '../_fixtures/selectors';
import { readRestNonce, postIdOfCard, deletePostRest, restPost } from '../_fixtures/feed-wave1.helpers';
import { dbScalar, tablePrefix } from '../_fixtures/wp';

/**
 * J-517 delete own comment (B3).
 *
 * Written from the member's promise: "a comment I delete is gone from the thread
 * and stays gone after a reload." The coverage matrix had "Delete comment"
 * MISSING; the existing comment specs never reload, so they prove render, not
 * persistence.
 *
 * Effect-based with a reload round-trip (gold): two of my comments are seeded —
 * a target and a keeper. The target is deleted through the real inline Delete +
 * confirm dialog, then the page RELOADS and the thread is re-opened. The keeper
 * proves the thread actually loaded; the target must be ABSENT (a soft-deleted
 * leaf is dropped from the thread — CommentService only returns tombstones that
 * still have live replies) and the DB row is asserted is_deleted=1 (the delete
 * persisted server-side, not just greyed a node client-side). Post deleted in
 * `finally`.
 */
test.describe('feed / comment delete', () => {
    const commentList = '.bn-comment-list';
    const commentCard = '.bn-comment-card';
    const deleteBtn = '.bn-comment__delete-btn';

    test('J-517 deleting my own comment removes it from the thread after reload', async ({ authenticatedPage: page }) => {
        const stamp = Date.now().toString().slice(-6);
        const postText = `j517 host post ${stamp}`;
        const targetText = `j517 delete me ${stamp}`;
        const keeperText = `j517 keep me ${stamp}`;
        let postId = 0;
        let targetId = 0;
        let keeperId = 0;
        let nonce = '';

        const openThread = async () => {
            const card = page.locator(sel.postCard).filter({ hasText: postText }).first();
            await expect(card).toBeVisible({ timeout: 10_000 });
            await card.locator(sel.postComment).first().click();
            await expect(card.locator(commentList).first()).toBeVisible({ timeout: 5_000 });
            return card;
        };

        try {
            await page.goto(urls.feed);
            await expect(page.locator(sel.composer).first()).toBeVisible();
            nonce = await readRestNonce(page);
            await page.locator(sel.composerTextarea).first().fill(postText);
            await page.locator(sel.composerSubmit).first().click();
            await expect(page.locator(sel.postCard).filter({ hasText: postText }).first()).toBeVisible({ timeout: 10_000 });
            await page.goto(urls.feed);
            postId = await postIdOfCard(page, postText);
            expect(postId).toBeGreaterThan(0);

            // Seed a keeper first, then the target (both mine).
            const keeper = await restPost<{ id?: number }>(page.request, nonce, '/comments', { object_type: 'post', object_id: postId, content: keeperText });
            keeperId = keeper.body.id ?? 0;
            const target = await restPost<{ id?: number }>(page.request, nonce, '/comments', { object_type: 'post', object_id: postId, content: targetText });
            targetId = target.body.id ?? 0;
            expect(keeperId).toBeGreaterThan(0);
            expect(targetId).toBeGreaterThan(0);

            // Open the thread and delete the target via its inline Delete + confirm.
            await page.goto(urls.feed);
            let card = await openThread();
            const node = card.locator(`${commentCard}[data-comment-id="${targetId}"]`).first();
            await expect(node).toBeVisible({ timeout: 8_000 });
            await node.locator(deleteBtn).first().click();

            const modal = page.locator('.bn-modal-backdrop').last();
            await expect(modal).toBeVisible({ timeout: 5_000 });
            await modal.getByRole('button', { name: 'Delete', exact: true }).first().click();
            await expect(modal).toBeHidden({ timeout: 5_000 });

            // Round-trip: reload, reopen. Keeper present (thread loaded), target gone.
            await page.goto(urls.feed);
            card = await openThread();
            await expect(card.locator(`${commentCard}[data-comment-id="${keeperId}"]`).first()).toBeVisible({ timeout: 8_000 });
            await expect(card.locator(`${commentCard}[data-comment-id="${targetId}"]`)).toHaveCount(0);

            // Server truth: the target row is soft-deleted, not merely hidden.
            const p = await tablePrefix();
            const flag = await dbScalar(`SELECT is_deleted FROM ${p}bn_comments WHERE id=${targetId};`);
            expect(flag, 'target comment is soft-deleted').toBe('1');
        } finally {
            await deletePostRest(page.request, nonce, postId).catch(() => {});
        }
    });
});
