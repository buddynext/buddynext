import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip, resolveOtherMemberSlug } from '../_fixtures/precondition';
import { sel } from '../_fixtures/selectors';
import { openMemberSession, restPost, type MemberSession } from '../_fixtures/feed-wave1.helpers';
import { userId, tablePrefix, dbCount, resetPair } from '../_fixtures/wp';

/**
 * J-515 follow-from-card (B2).
 *
 * Written from the member's promise: "I can follow a post's author straight
 * from the feed card, and the follow actually happens." The coverage matrix had
 * "Follow-from-card" MISSING; the directory Follow (J-27) is WEAK (label flip,
 * no DB) and a different surface.
 *
 * Effect-based via DB truth: the spec seeds a post from a SECOND member the
 * viewer does not follow, opens that post's permalink (where the byline Follow
 * pill renders for a not-yet-followed author), clicks Follow, and asserts a real
 * bn_follows row (follower=viewer, following=author) appears. An optimistic
 * button that flips to "Following" while the REST write 500s would leave the
 * table empty and fail here. The follow + the seeded post are torn down in
 * `finally`.
 */
test.describe('feed / follow from card', () => {
    const followPill = '.bn-post-card__follow .bn-follow-btn';

    test('J-515 following a card author creates the follow relationship', async ({ authenticatedPage: page, browser }, testInfo) => {
        const authorSlug = await resolveOtherMemberSlug(page, 'varundubey');
        if (!authorSlug) {
            softSkip(testInfo, 'No other member to author the followed post.');
            return;
        }

        const meId = await userId('varundubey');
        const authorId = await userId(authorSlug);
        expect(meId).toBeGreaterThan(0);
        expect(authorId).toBeGreaterThan(0);

        const stamp = Date.now().toString().slice(-6);
        let author: MemberSession | null = null;
        let postId = 0;
        const p = await tablePrefix();
        const followSql = `SELECT COUNT(*) FROM ${p}bn_follows WHERE follower_id=${meId} AND following_id=${authorId};`;

        try {
            // Clean slate: the viewer must NOT already follow the author (else the
            // pill would not render and the created-row assertion would be moot).
            await resetPair(meId, authorId);

            // Seed a public post authored by the OTHER member.
            author = await openMemberSession(browser, authorSlug);
            const created = await restPost<{ id?: number }>(author.request, author.nonce, '/posts', {
                content: `j515 follow me ${stamp}`,
                privacy: 'public',
            });
            expect(created.status, `seed post -> ${created.status}`).toBeLessThan(300);
            postId = created.body.id ?? 0;
            expect(postId).toBeGreaterThan(0);

            // View the post on its permalink; the byline Follow pill renders because
            // the viewer does not yet follow this author.
            await page.goto(`/p/${postId}/`, { waitUntil: 'domcontentloaded' });
            const card = page.locator(sel.postCard).filter({ hasText: `j515 follow me ${stamp}` }).first();
            await expect(card).toBeVisible({ timeout: 10_000 });

            const pill = card.locator(followPill).first();
            if (!(await pill.isVisible().catch(() => false))) {
                softSkip(testInfo, 'Byline Follow pill absent (privacy who_can_follow, or already following) — nothing to click.');
                return;
            }
            await expect(pill).toHaveText(/follow/i);

            // Precondition truth: no follow yet.
            expect(await dbCount(followSql)).toBe(0);

            await pill.click();

            // Effect: the follow row is written. Poll to absorb the REST round-trip.
            await expect.poll(async () => dbCount(followSql), { timeout: 8_000 }).toBe(1);
        } finally {
            await resetPair(meId, authorId).catch(() => {});
            if (author && postId) {
                await author.request
                    .delete(`/wp-json/buddynext/v1/posts/${postId}`, { headers: { 'X-WP-Nonce': author.nonce } })
                    .catch(() => undefined);
            }
            if (author) {
                await author.ctx.close().catch(() => {});
            }
        }
    });
});
