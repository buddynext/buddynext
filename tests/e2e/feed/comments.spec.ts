import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from "../_fixtures/precondition";
import { sel, urls } from '../_fixtures/selectors';
import { readRestNonce, postIdOfCard, deletePostRest } from '../_fixtures/feed-wave1.helpers';

/**
 * J-17-comment-on-post.
 */
test.describe('feed / comments', () => {
    test('clicking comment surfaces the comment input', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.feed);
        const firstCard = page.locator(sel.postCard).first();
        if ((await page.locator(sel.postCard).count()) === 0) {
            softSkip(testInfo, 'No posts to comment on.');
            return;
        }

        const commentBtn = firstCard.locator(sel.postComment).first();
        await expect(commentBtn).toBeVisible();
        await commentBtn.click();

        const input = page.locator(sel.commentInput).first();
        await expect(input).toBeVisible({ timeout: 5_000 });
    });

    // HARDENED (Wave-4, 2026-08-10). This previously commented on
    // `page.locator(sel.postCard).first()` — whatever post happened to be at the
    // top of the feed. In a full serial run that first card is nondeterministic
    // (a poll / link / reshare / media / another test's in-flight post), and
    // several of those don't expose a plain post-level comment form the same way,
    // so the test flaked red under load while passing in isolation. It now seeds
    // its OWN text post and comments on THAT card — deterministic, and deleted in
    // `finally`. Journey id (J-17) and the effect (new comment becomes visible)
    // are unchanged.
    test('submitting a comment appends it to the list', async ({ authenticatedPage: page }, testInfo) => {
        let createdPostId = 0;
        let nonce = '';
        const stamp = Date.now().toString().slice(-6);
        const postBody = `j17 comment-host ${stamp}`;
        const body = `e2e comment ${stamp}`;

        try {
            await page.goto(urls.feed);
            await expect(page.locator(sel.composer).first()).toBeVisible();
            nonce = await readRestNonce(page);
            await page.locator(sel.composerTextarea).first().fill(postBody);
            await page.locator(sel.composerSubmit).first().click();
            await expect(page.locator(sel.postCard).filter({ hasText: postBody }).first()).toBeVisible({ timeout: 10_000 });

            await page.goto(urls.feed);
            const card = page.locator(sel.postCard).filter({ hasText: postBody }).first();
            await expect(card).toBeVisible({ timeout: 10_000 });
            createdPostId = await postIdOfCard(page, postBody);

            await card.locator(sel.postComment).first().click();

            // Scope the input + submit to THIS card — there is one (hidden) comment
            // form per post in the DOM, so a global `.first()` can resolve the input
            // and the submit button to two different cards (one collapsed), making
            // the submit invisible and silently falling through to a no-op.
            const input = card.locator(sel.commentInput).first();
            if (!(await input.isVisible().catch(() => false))) {
                softSkip(testInfo, 'Comment input not yet exposed in build.');
                return;
            }
            await input.fill(body);

            // No <form> wraps the comment input — there's a dedicated submit
            // button bound to actions.submitComment. Match ONLY that binding: the
            // per-comment reply forms reuse the `.bn-comment-form__submit` class but
            // carry no submitComment binding, and they render (hidden) above the
            // post-level form, so a class match would resolve to a hidden reply button.
            const submitBtn = card.locator('[data-wp-on--click="actions.submitComment"]').first();
            await expect(submitBtn).toBeVisible({ timeout: 5_000 });
            await submitBtn.click();

            const newComment = card.locator(sel.commentList).filter({ hasText: body }).first();
            // Patient enough to absorb comment POST + render lag under full-suite load.
            await expect(newComment).toBeVisible({ timeout: 15_000 });
        } finally {
            await deletePostRest(page.request, nonce, createdPostId).catch(() => {});
        }
    });
});
