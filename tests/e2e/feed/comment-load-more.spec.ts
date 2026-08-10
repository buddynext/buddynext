import { test, expect } from '../_fixtures/auth.fixture';
import { sel, urls } from '../_fixtures/selectors';
import { readRestNonce, postIdOfCard, deletePostRest, restPost } from '../_fixtures/feed-wave1.helpers';

/**
 * J-519 comment load-more (B3).
 *
 * Written from the member's promise: "when a post has more comments than one
 * page, I can load the older ones." The coverage matrix had "Load-more
 * comments" MISSING.
 *
 * Effect-based: the spec seeds 23 comments (> the page size of 20) on a post,
 * opens the thread, asserts the first page renders exactly 20 comment cards
 * plus a "View more comments" control, clicks it, and asserts the rendered
 * count grows past 20 (the older page actually loaded from
 * GET /comments?page=2). A pager wired to nothing would leave the count at 20
 * and fail. Post deleted in `finally` (its comments go with it).
 */
test.describe('feed / comment load-more', () => {
    const commentList = '.bn-comment-list';
    const commentCard = '.bn-comment-card';
    const loadMore = '.bn-comment-loadmore';
    const PAGE_SIZE = 20;
    const SEED = 23;

    test('J-519 a thread past one page loads older comments on demand', async ({ authenticatedPage: page }) => {
        const stamp = Date.now().toString().slice(-6);
        const postText = `j519 busy thread ${stamp}`;
        let postId = 0;
        let nonce = '';

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

            // Seed more than one page of comments (sequentially — one shared DB).
            for (let i = 1; i <= SEED; i++) {
                const c = await restPost<{ id?: number }>(page.request, nonce, '/comments', {
                    object_type: 'post',
                    object_id: postId,
                    content: `j519 c${i} ${stamp}`,
                });
                expect(c.status, `seed comment ${i} -> ${c.status}`).toBeLessThan(300);
            }

            // Open the thread: first page shows PAGE_SIZE cards + a load-more control.
            await page.goto(urls.feed);
            const card = page.locator(sel.postCard).filter({ hasText: postText }).first();
            await expect(card).toBeVisible({ timeout: 10_000 });
            await card.locator(sel.postComment).first().click();
            await expect(card.locator(commentList).first()).toBeVisible({ timeout: 5_000 });

            const cards = card.locator(`${commentList} ${commentCard}`);
            await expect.poll(async () => cards.count(), { timeout: 8_000 }).toBe(PAGE_SIZE);

            const more = card.locator(loadMore).first();
            await expect(more).toBeVisible({ timeout: 5_000 });
            await more.click();

            // Older page loaded — the rendered count grows beyond one page.
            await expect.poll(async () => cards.count(), { timeout: 8_000 }).toBeGreaterThan(PAGE_SIZE);
        } finally {
            await deletePostRest(page.request, nonce, postId).catch(() => {});
        }
    });
});
