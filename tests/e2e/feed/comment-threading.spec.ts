import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from "../_fixtures/precondition";
import { sel, urls } from '../_fixtures/selectors';
import { readRestNonce, postIdOfCard, deletePostRest, restGet } from '../_fixtures/feed-wave1.helpers';

type ReactionCount = { count: number; has_reacted?: boolean };

/**
 * J-121-comment-threading.
 *
 * Wave-2 A4: threaded comment UI — Reply / Like / Edit / Delete.
 *
 * Each test soft-skips when no posts exist on the seeded environment;
 * the CI fixtures aren't guaranteed to ship a post on every run.
 */
test.describe('feed / comment threading', () => {

    // WEAK ASSERTION REPLACED (Wave-3, 2026-08-10). The prior comment-Like test
    // asserted only the button's own `data-liked` attribute flipping 0->1 — a
    // pure optimistic-UI signal that flips client-side even when
    // `POST /reactions/toggle {object_type:comment}` silently 500s. It is now
    // effect-based: after liking we read server truth from
    // `GET /reactions?object_type=comment&object_id={commentId}` and assert the
    // count went 0->1 with `has_reacted=true`. Runs against a FRESH OWN post
    // (deleted in finally) so the comment + its like are fully self-contained.
    test('J-121 liking a comment writes a reaction row (server truth, not just data-liked)', async ({ authenticatedPage: page }, testInfo) => {
        let createdPostId = 0;
        let nonce = '';
        const stamp = Date.now().toString().slice(-6);
        const postBody = `j121 like-host ${stamp}`;

        try {
            // Seed an own post to host the comment.
            await page.goto(urls.feed);
            await expect(page.locator(sel.composer).first()).toBeVisible();
            nonce = await readRestNonce(page);
            await page.locator(sel.composerTextarea).first().fill(postBody);
            await page.locator(sel.composerSubmit).first().click();
            await expect(page.locator(sel.postCard).filter({ hasText: postBody }).first()).toBeVisible({ timeout: 10_000 });
            await page.goto(urls.feed);
            const hostCard = page.locator(sel.postCard).filter({ hasText: postBody }).first();
            await expect(hostCard).toBeVisible({ timeout: 10_000 });
            createdPostId = await postIdOfCard(page, postBody);

            // Open comments and post a comment we can then like.
            await hostCard.locator(sel.postComment).first().click();
            const input = hostCard.locator(sel.commentInput).first();
            if (!(await input.isVisible().catch(() => false))) {
                softSkip(testInfo, 'Comment input not exposed.');
                return;
            }
            await input.fill(`like target ${stamp}`);
            await hostCard.locator('[data-wp-on--click="actions.submitComment"]').first().click();

            const card = page.locator('.bn-comment-card', { hasText: `like target ${stamp}` }).first();
            await expect(card).toBeVisible({ timeout: 8_000 });
            const commentId = Number(await card.getAttribute('data-comment-id'));
            expect(commentId).toBeGreaterThan(0);

            const reactionPath = `/reactions?object_type=comment&object_id=${commentId}`;
            const readCount = async (): Promise<ReactionCount> =>
                (await restGet<ReactionCount>(page.request, nonce, reactionPath)).body;

            // Precondition truth: the new comment has no likes.
            expect((await readCount()).count).toBe(0);

            const likeBtn = card.locator('.bn-comment__like-btn');
            await expect(likeBtn).toHaveAttribute('data-liked', '0');
            await likeBtn.click();
            // Optimistic signal still checked...
            await expect(likeBtn).toHaveAttribute('data-liked', '1');
            // ...but the real assertion is server truth: the reaction row exists.
            await expect.poll(async () => (await readCount()).count, { timeout: 8_000 }).toBeGreaterThanOrEqual(1);
            expect((await readCount()).has_reacted).toBe(true);
        } finally {
            await deletePostRest(page.request, nonce, createdPostId).catch(() => {});
        }
    });

    // HARDENED (Wave-4, 2026-08-10). Was rooted on `page.locator(sel.postCard)
    // .first()` — a nondeterministic top-of-feed card that, under full-suite load,
    // could be a post shape that doesn't expose the plain comment form, flaking
    // this red while it passed in isolation. It now seeds its OWN post and
    // comments on THAT card; deleted in `finally`. Journey id + effect unchanged.
    test('reply button opens an inline form and posts a nested reply', async ({ authenticatedPage: page }, testInfo) => {
        let createdPostId = 0;
        let nonce = '';
        const stamp = Date.now().toString().slice(-6);
        const postBody = `j121 reply-host ${stamp}`;

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

            await host.locator(sel.postComment).first().click();
            const input = host.locator(sel.commentInput).first();
            if (!(await input.isVisible().catch(() => false))) {
                softSkip(testInfo, 'Comment input not exposed.');
                return;
            }
            await input.fill(`reply parent ${stamp}`);
            await host.locator('[data-wp-on--click="actions.submitComment"]').first().click();

            // Scoped to the host card + patient for comment-render lag under load.
            const parent = host.locator('.bn-comment-card', { hasText: `reply parent ${stamp}` }).first();
            await expect(parent).toBeVisible({ timeout: 15_000 });

            await parent.locator('.bn-comment__reply-btn').click();
            const replyTa = parent.locator('.bn-comment__reply-form textarea').first();
            await expect(replyTa).toBeVisible();
            await replyTa.fill(`nested ${stamp}`);
            await parent.locator('.bn-comment__reply-form .bn-comment-form__submit').click();

            await expect(parent.locator('.bn-comment__replies .bn-comment-card', { hasText: `nested ${stamp}` })).toBeVisible({ timeout: 15_000 });
        } finally {
            await deletePostRest(page.request, nonce, createdPostId).catch(() => {});
        }
    });

    // HARDENED (Wave-4, 2026-08-10). Same fix as the reply test above — seed an
    // OWN post instead of commenting on the feed's first arbitrary card.
    test('edit own comment swaps content + flags as edited', async ({ authenticatedPage: page }, testInfo) => {
        let createdPostId = 0;
        let nonce = '';
        const stamp = Date.now().toString().slice(-6);
        const postBody = `j121 edit-host ${stamp}`;

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

            await host.locator(sel.postComment).first().click();
            const input = host.locator(sel.commentInput).first();
            if (!(await input.isVisible().catch(() => false))) {
                softSkip(testInfo, 'Comment input not exposed.');
                return;
            }
            await input.fill(`edit me ${stamp}`);
            await host.locator('[data-wp-on--click="actions.submitComment"]').first().click();

            // Scoped to the host card + patient for comment-render lag under load.
            const seedCard = host.locator('.bn-comment-card', { hasText: `edit me ${stamp}` }).first();
            await expect(seedCard).toBeVisible({ timeout: 15_000 });

            // Pin the card by its stable comment id BEFORE editing — a successful
            // edit rewrites the visible text to `edited ${stamp}`, so a card locator
            // filtered on the original `edit me ${stamp}` text would stop matching.
            const commentId = await seedCard.getAttribute('data-comment-id');
            const card = page.locator(`.bn-comment-card[data-comment-id="${commentId}"]`);

            await card.locator('.bn-comment__edit-btn').click();
            const editTa = card.locator('.bn-comment__edit-form textarea');
            await expect(editTa).toBeVisible();
            await editTa.fill(`edited ${stamp}`);
            await card.locator('.bn-comment__edit-form .bn-comment-form__submit').click();

            await expect(card.locator('.bn-comment__content', { hasText: `edited ${stamp}` })).toBeVisible({ timeout: 15_000 });
            await expect(card.locator('.bn-comment__edited')).toBeVisible();
        } finally {
            await deletePostRest(page.request, nonce, createdPostId).catch(() => {});
        }
    });
});
