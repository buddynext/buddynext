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

        const input = page.locator(sel.commentInput).first();
        if (!(await input.isVisible().catch(() => false))) {
            softSkip(testInfo, 'Comment input not yet exposed in build.');
            return;
        }

        const stamp = Date.now().toString().slice(-6);
        const body = `e2e comment ${stamp}`;
        await input.fill(body);

        // No <form> wraps the comment input — there's a dedicated submit
        // button bound to actions.submitComment.
        const submitBtn = page.locator('.bn-comment-form__submit, [data-wp-on--click="actions.submitComment"]').first();
        if (await submitBtn.isVisible().catch(() => false)) {
            await submitBtn.click();
        } else {
            await input.press('Enter');
        }

        const newComment = page.locator(sel.commentList).filter({ hasText: body }).first();
        await expect(newComment).toBeVisible({ timeout: 8_000 });
    });
});
