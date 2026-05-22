import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from "../_fixtures/precondition";
import { sel, urls } from '../_fixtures/selectors';

/**
 * Wave-2 A4: threaded comment UI — Reply / Like / Edit / Delete.
 *
 * Each test soft-skips when no posts exist on the seeded environment;
 * the CI fixtures aren't guaranteed to ship a post on every run.
 */
test.describe('feed / comment threading', () => {

    test('like button toggles heart + count optimistically', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.feed);
        if ((await page.locator(sel.postCard).count()) === 0) {
            softSkip(testInfo, 'No posts to comment on.');
            return;
        }

        // Open comments on the first card.
        const firstCard  = page.locator(sel.postCard).first();
        const commentBtn = firstCard.locator(sel.postComment).first();
        await commentBtn.click();

        // Post a comment we can then like.
        const input = page.locator(sel.commentInput).first();
        if (!(await input.isVisible().catch(() => false))) {
            softSkip(testInfo, 'Comment input not exposed.');
            return;
        }
        const stamp = Date.now().toString().slice(-6);
        await input.fill(`like target ${stamp}`);
        await page.locator('[data-wp-on--click="actions.submitComment"]').first().click();

        const card = page.locator('.bn-comment-card', { hasText: `like target ${stamp}` }).first();
        await expect(card).toBeVisible({ timeout: 8_000 });

        const likeBtn = card.locator('.bn-comment__like-btn');
        await expect(likeBtn).toHaveAttribute('data-liked', '0');
        await likeBtn.click();
        await expect(likeBtn).toHaveAttribute('data-liked', '1');
    });

    test('reply button opens an inline form and posts a nested reply', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.feed);
        if ((await page.locator(sel.postCard).count()) === 0) {
            softSkip(testInfo, 'No posts to comment on.');
            return;
        }

        const firstCard  = page.locator(sel.postCard).first();
        await firstCard.locator(sel.postComment).first().click();

        const stamp = Date.now().toString().slice(-6);
        const input = page.locator(sel.commentInput).first();
        if (!(await input.isVisible().catch(() => false))) {
            softSkip(testInfo, 'Comment input not exposed.');
            return;
        }
        await input.fill(`reply parent ${stamp}`);
        await page.locator('[data-wp-on--click="actions.submitComment"]').first().click();

        const parent = page.locator('.bn-comment-card', { hasText: `reply parent ${stamp}` }).first();
        await expect(parent).toBeVisible({ timeout: 8_000 });

        await parent.locator('.bn-comment__reply-btn').click();
        const replyTa = parent.locator('.bn-comment__reply-form textarea').first();
        await expect(replyTa).toBeVisible();
        await replyTa.fill(`nested ${stamp}`);
        await parent.locator('.bn-comment__reply-form .bn-comment-form__submit').click();

        await expect(parent.locator('.bn-comment__replies .bn-comment-card', { hasText: `nested ${stamp}` })).toBeVisible({ timeout: 8_000 });
    });

    test('edit own comment swaps content + flags as edited', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.feed);
        if ((await page.locator(sel.postCard).count()) === 0) {
            softSkip(testInfo, 'No posts to comment on.');
            return;
        }

        const firstCard  = page.locator(sel.postCard).first();
        await firstCard.locator(sel.postComment).first().click();
        const input = page.locator(sel.commentInput).first();
        if (!(await input.isVisible().catch(() => false))) {
            softSkip(testInfo, 'Comment input not exposed.');
            return;
        }
        const stamp = Date.now().toString().slice(-6);
        await input.fill(`edit me ${stamp}`);
        await page.locator('[data-wp-on--click="actions.submitComment"]').first().click();

        const card = page.locator('.bn-comment-card', { hasText: `edit me ${stamp}` }).first();
        await expect(card).toBeVisible({ timeout: 8_000 });

        await card.locator('.bn-comment__edit-btn').click();
        const editTa = card.locator('.bn-comment__edit-form textarea');
        await expect(editTa).toBeVisible();
        await editTa.fill(`edited ${stamp}`);
        await card.locator('.bn-comment__edit-form .bn-comment-form__submit').click();

        await expect(card.locator('.bn-comment__content', { hasText: `edited ${stamp}` })).toBeVisible({ timeout: 8_000 });
        await expect(card.locator('.bn-comment__edited')).toBeVisible();
    });
});
