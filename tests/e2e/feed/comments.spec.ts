import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from "../_fixtures/precondition";
import { sel, urls } from '../_fixtures/selectors';

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

    test('submitting a comment appends it to the list', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.feed);
        if ((await page.locator(sel.postCard).count()) === 0) {
            softSkip(testInfo, 'No posts to comment on.');
            return;
        }

        const firstCard = page.locator(sel.postCard).first();
        const commentBtn = firstCard.locator(sel.postComment).first();
        if (await commentBtn.isVisible().catch(() => false)) {
            await commentBtn.click();
        }

        // Scope the input + submit to THIS card — there is one (hidden) comment
        // form per post in the DOM, so a global `.first()` can resolve the input
        // and the submit button to two different cards (one collapsed), making
        // the submit invisible and silently falling through to a no-op.
        const input = firstCard.locator(sel.commentInput).first();
        if (!(await input.isVisible().catch(() => false))) {
            softSkip(testInfo, 'Comment input not yet exposed in build.');
            return;
        }

        const stamp = Date.now().toString().slice(-6);
        const body = `e2e comment ${stamp}`;
        await input.fill(body);

        // No <form> wraps the comment input — there's a dedicated submit
        // button bound to actions.submitComment. Match ONLY that binding: the
        // per-comment reply forms reuse the `.bn-comment-form__submit` class but
        // carry no submitComment binding, and they render (hidden) above the
        // post-level form, so a class match would resolve to a hidden reply button.
        const submitBtn = firstCard.locator('[data-wp-on--click="actions.submitComment"]').first();
        await expect(submitBtn).toBeVisible({ timeout: 5_000 });
        await submitBtn.click();

        const newComment = firstCard.locator(sel.commentList).filter({ hasText: body }).first();
        await expect(newComment).toBeVisible({ timeout: 8_000 });
    });
});
