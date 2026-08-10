import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip, resolveOtherMemberSlug } from '../_fixtures/precondition';
import { sel, urls } from '../_fixtures/selectors';
import { readRestNonce, postIdOfCard, deletePostRest, openMemberSession, type MemberSession } from '../_fixtures/feed-wave1.helpers';

/**
 * J-513 composer announcement — admin only (B1 / B2).
 *
 * Written from the member's promise: "an admin can post an announcement and it
 * renders as one; a normal member has no way to." The coverage matrix had
 * "Announcement (admin only)" MISSING.
 *
 * Effect-based, two-sided: (1) as the admin (varundubey) the announcement tool
 * is used and, after a reload, the resulting card carries the announcement
 * treatment (.bn-post-card--announcement + its banner bar) — proving the post
 * was actually stored is_announcement, not merely typed; (2) a non-admin
 * member's composer has NO announcement control at all (the gate removes it,
 * per partials/composer.php current_user_can('manage_options')). Post deleted
 * in `finally`.
 */
test.describe('feed / composer announcement', () => {
    const announceTool = 'button.bn-composer__tool[aria-label="Post as announcement"]';

    test('J-513 admin announcement renders as an announcement; a non-admin has no control', async ({ authenticatedPage: page, browser }, testInfo) => {
        const stamp = Date.now().toString().slice(-6);
        const content = `j513 announcement ${stamp}`;
        let postId = 0;
        let nonce = '';
        let member: MemberSession | null = null;

        try {
            await page.goto(urls.feed);
            const composer = page.locator(sel.composer).first();
            await expect(composer).toBeVisible();
            nonce = await readRestNonce(page);

            const tool = page.locator(announceTool).first();
            expect(await tool.count(), 'admin must have the announcement tool').toBeGreaterThan(0);

            await page.locator(sel.composerTextarea).first().fill(content);
            await tool.click();
            await expect(tool).toHaveAttribute('aria-pressed', 'true', { timeout: 5_000 });
            await page.locator(sel.composerSubmit).first().click();
            await expect(page.locator(sel.postCard).filter({ hasText: content }).first()).toBeVisible({ timeout: 10_000 });

            // Reload → server-rendered card must carry the announcement treatment.
            await page.goto(urls.feed);
            const card = page.locator(sel.postCard).filter({ hasText: content }).first();
            await expect(card).toBeVisible({ timeout: 10_000 });
            postId = await postIdOfCard(page, content);
            await expect(card).toHaveClass(/bn-post-card--announcement/);
            await expect(card.locator('.bn-post-card__announcement-bar')).toBeVisible();

            // Non-admin: the announcement control is not merely disabled, it is absent.
            const otherSlug = await resolveOtherMemberSlug(page, 'varundubey');
            if (!otherSlug) {
                softSkip(testInfo, 'No non-admin member to assert the announcement gate.');
            } else {
                member = await openMemberSession(browser, otherSlug);
                await member.page.goto(urls.feed, { waitUntil: 'domcontentloaded' });
                // The composer may be absent for restricted members; either way there
                // must be zero announcement tools for a non-admin.
                await expect(member.page.locator(announceTool)).toHaveCount(0);
            }
        } finally {
            await deletePostRest(page.request, nonce, postId).catch(() => {});
            if (member) {
                await member.ctx.close().catch(() => {});
            }
        }
    });
});
