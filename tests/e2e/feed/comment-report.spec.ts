import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip, resolveOtherMemberSlug } from '../_fixtures/precondition';
import { sel, urls } from '../_fixtures/selectors';
import { readRestNonce, postIdOfCard, deletePostRest, openMemberSession, restPost, type MemberSession } from '../_fixtures/feed-wave1.helpers';

/**
 * J-518 report a comment (B3).
 *
 * Written from the member's promise: "I can report someone else's comment, and
 * I can't report the same one twice." The coverage matrix had "Report comment"
 * MISSING. Mirrors the post-report gold (report.spec J-506) at the comment level.
 *
 * Effect-based server truth: after reporting another member's comment through
 * the real report dialog, a direct REST re-report of the same comment must come
 * back 409 already_reported — which proves the first report was actually
 * written (a silently-failed UI report would make the REST call the FIRST report
 * and return 201, failing this). Host post + seeded comment torn down in
 * `finally`.
 */
test.describe('feed / comment report', () => {
    const commentList = '.bn-comment-list';
    const commentCard = '.bn-comment-card';
    const reportBtn = '.bn-comment__report-btn';

    test('J-518 reporting another member\'s comment records it; a repeat is 409', async ({ authenticatedPage: page, browser }, testInfo) => {
        const authorSlug = await resolveOtherMemberSlug(page, 'varundubey');
        if (!authorSlug) {
            softSkip(testInfo, 'No other member to author the reported comment.');
            return;
        }

        const stamp = Date.now().toString().slice(-6);
        const postText = `j518 host ${stamp}`;
        const commentText = `j518 flag me ${stamp}`;
        let postId = 0;
        let commentId = 0;
        let nonce = '';
        let author: MemberSession | null = null;

        try {
            // Host post is mine.
            await page.goto(urls.feed);
            await expect(page.locator(sel.composer).first()).toBeVisible();
            nonce = await readRestNonce(page);
            await page.locator(sel.composerTextarea).first().fill(postText);
            await page.locator(sel.composerSubmit).first().click();
            await expect(page.locator(sel.postCard).filter({ hasText: postText }).first()).toBeVisible({ timeout: 10_000 });
            await page.goto(urls.feed);
            postId = await postIdOfCard(page, postText);
            expect(postId).toBeGreaterThan(0);

            // The comment is authored by the OTHER member (so Report is offered to me).
            author = await openMemberSession(browser, authorSlug);
            const c = await restPost<{ id?: number }>(author.request, author.nonce, '/comments', {
                object_type: 'post',
                object_id: postId,
                content: commentText,
            });
            expect(c.status, `seed comment -> ${c.status}`).toBeLessThan(300);
            commentId = c.body.id ?? 0;
            expect(commentId).toBeGreaterThan(0);

            // Open the thread as me and report the other member's comment.
            await page.goto(urls.feed);
            const card = page.locator(sel.postCard).filter({ hasText: postText }).first();
            await expect(card).toBeVisible({ timeout: 10_000 });
            await card.locator(sel.postComment).first().click();
            await expect(card.locator(commentList).first()).toBeVisible({ timeout: 5_000 });
            const node = card.locator(`${commentCard}[data-comment-id="${commentId}"]`).first();
            await expect(node).toBeVisible({ timeout: 8_000 });

            const rbtn = node.locator(reportBtn).first();
            if (!(await rbtn.isVisible().catch(() => false))) {
                softSkip(testInfo, 'Comment Report control absent (moderation/report feature may be off).');
                return;
            }
            await rbtn.click();

            // bnReportDialog — reason <select> + Submit report.
            const modal = page.locator('.bn-modal-backdrop', { has: page.locator('select.bn-input') }).last();
            await expect(modal).toBeVisible({ timeout: 5_000 });
            await modal.locator('select.bn-input').first().selectOption('spam').catch(() => {});
            await modal.getByRole('button', { name: /submit report|report/i }).first().click();
            await expect(modal).toBeHidden({ timeout: 5_000 });

            // Server truth: the same comment reported again is rejected 409.
            const repeat = await restPost<{ code?: string }>(page.request, nonce, '/reports', {
                object_type: 'comment',
                object_id: commentId,
                reason: 'spam',
            });
            expect(repeat.status, `repeat report -> ${repeat.status} (expected 409)`).toBe(409);
        } finally {
            await deletePostRest(page.request, nonce, postId).catch(() => {});
            if (author) {
                await author.ctx.close().catch(() => {});
            }
        }
    });
});
