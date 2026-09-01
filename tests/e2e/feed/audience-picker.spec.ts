import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip, resolveOtherMemberSlug } from '../_fixtures/precondition';
import { sel, urls } from '../_fixtures/selectors';
import { readRestNonce, postIdOfCard, deletePostRest, openMemberSession, type MemberSession } from '../_fixtures/feed-wave1.helpers';
import { userId, dbScalar, tablePrefix, resetPair } from '../_fixtures/wp';

/**
 * J-510 composer audience picker (B1).
 *
 * Written from the member's promise: "when I post to Followers, the audience I
 * chose is what the server stores, and someone who does not follow me cannot
 * see the post." The coverage matrix had "Audience picker" MISSING — no spec
 * drives the privacy chip at all.
 *
 * Effect-based, two-sided: after composing a followers-only post through the
 * real chip the spec (1) reads bn_posts.privacy directly and asserts it is
 * 'followers' (the audience actually persisted, not an optimistic label), and
 * (2) opens a SECOND member's session — a member who does NOT follow the author
 * — and confirms the post's permalink does not render the card for them. A
 * picker that flips its own label but posts 'public' anyway would fail both
 * legs. The post is deleted in `finally` so the feed is left as found.
 */
test.describe('feed / composer audience picker', () => {
    const privacyChip = '.bn-composer__privacy-chip';
    const followersOpt = '.bn-composer__privacy-opt[data-privacy="followers"]';

    test('J-510 a followers-only post persists privacy=followers and is hidden from a non-follower', async ({ authenticatedPage: page, browser }, testInfo) => {
        const otherSlug = await resolveOtherMemberSlug(page, 'varundubey');
        if (!otherSlug) {
            softSkip(testInfo, 'No second member to act as the non-follower viewer.');
            return;
        }

        const meId = await userId(process.env.BN_TEST_USER ?? 'varundubey');
        const themId = await userId(otherSlug);
        expect(meId, 'author id').toBeGreaterThan(0);
        expect(themId, 'viewer id').toBeGreaterThan(0);

        const stamp = Date.now().toString().slice(-6);
        const content = `j510 followers only ${stamp}`;
        let postId = 0;
        let nonce = '';
        let viewer: MemberSession | null = null;

        try {
            // Guarantee the viewer does NOT follow the author, so "followers-only"
            // genuinely excludes them (both directions cleared for good measure).
            await resetPair(meId, themId);

            await page.goto(urls.feed);
            const composer = page.locator(sel.composer).first();
            await expect(composer).toBeVisible();
            nonce = await readRestNonce(page);

            await page.locator(sel.composerTextarea).first().fill(content);

            // Drive the real audience picker: open the chip, choose Followers.
            await page.locator(privacyChip).first().click();
            const opt = page.locator(followersOpt).first();
            await expect(opt).toBeVisible({ timeout: 5_000 });
            await opt.click();
            // The chip now reads its chosen audience.
            await expect(page.locator(`${privacyChip} [data-wp-text="state.privacyLabel"]`).first()).toHaveText(/followers/i);

            await page.locator(sel.composerSubmit).first().click();
            await expect(page.locator(sel.postCard).filter({ hasText: content }).first()).toBeVisible({ timeout: 10_000 });

            // Reload so the card is server-rendered, then resolve its server id.
            await page.goto(urls.feed);
            await expect(page.locator(sel.postCard).filter({ hasText: content }).first()).toBeVisible({ timeout: 10_000 });
            postId = await postIdOfCard(page, content);
            expect(postId, 'resolved server post id').toBeGreaterThan(0);

            // Effect 1 — the audience actually persisted to the row.
            const p = await tablePrefix();
            const privacy = await dbScalar(`SELECT privacy FROM ${p}bn_posts WHERE id=${postId};`);
            expect(privacy, 'stored audience').toBe('followers');

            // Effect 2 — a non-follower cannot see the post on its permalink.
            viewer = await openMemberSession(browser, otherSlug);
            await viewer.page.goto(`/p/${postId}/`, { waitUntil: 'domcontentloaded' });
            await expect(viewer.page.locator('body')).toBeVisible();
            await expect(
                viewer.page.locator(sel.postCard).filter({ hasText: content })
            ).toHaveCount(0);
        } finally {
            await deletePostRest(page.request, nonce, postId).catch(() => {});
            await resetPair(meId, themId).catch(() => {});
            if (viewer) {
                await viewer.ctx.close().catch(() => {});
            }
        }
    });
});
