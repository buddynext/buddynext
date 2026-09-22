import { test, expect } from '../_fixtures/auth.fixture';
import { loginAs } from '../_fixtures/actor';
import { softSkip } from "../_fixtures/precondition";
import { sel, urls } from '../_fixtures/selectors';
import { userId, ensureUser, setUserMeta, getUserMeta, deleteUserMeta, tablePrefix, dbCount, wp } from '../_fixtures/wp';
import type { Page } from '@playwright/test';

const A_LOGIN = process.env.BN_TEST_USER ?? 'varundubey';

// Follow-from-card targets a member the actor does NOT already follow. Both the
// server-rendered card (member-card.php) and the JS-rebuilt card
// (members/store.js paintFollowBtn) stamp the Follow button with
// data-state="unfollowed" until the actor follows — so a card that OWNS such a
// button is a not-yet-followed member exposing a live Follow control.
const FOLLOWABLE_CARD = '.bn-md-card:has(.bn-md-card__follow[data-state="unfollowed"])';

// Count member cards, failing loudly when the page rendered neither cards nor an
// empty state.
//
// These specs used to bail with `if (count === 0) softSkip('No member cards
// seeded')`, which checks the very selector under test. A broken selector was
// therefore indistinguishable from an empty site, and both reported green — the
// reason J-27 and J-28 sat passing for so long while asserting nothing. Skipping
// is only honest when the directory positively says it is empty.
//
// Kept as `//` line comments rather than a JSDoc block on purpose: the
// role-coverage checker reads a spec's coverage tags from the first
// JSDoc-style block comment in the file, not from the one attached to
// test.describe below. A JSDoc block here shadowed the real journey docblock
// and its tags, so this whole file read as unpinned even though the tags do
// exist, below, on the journey docblock that actually proves them.
async function countMemberCards(page: Page): Promise<number> {
    const cards = await page.locator(sel.memberCard).count();
    if (cards === 0) {
        const empty = await page.locator(sel.memberDirectoryEmpty).isVisible().catch(() => false);
        if (!empty) {
            throw new Error(
                'members directory rendered neither member cards nor an empty state — ' +
                `the '${sel.memberCard}' selector is probably stale, not the site empty`
            );
        }
    }
    return cards;
}

/**
 * J-24-directory-members + J-27-directory-follow-from-card + J-28-directory-mute-from-card.
 *
 * Covers: cap-follow-people-and-connect-mutually, cap-show-a-member-s-cover-photo-on-their-directory-card
 * Roles: admin, member
 * Note: the base cap-show-a-member-s-cover-photo-on-their-directory-card pin
 * is a loose fit - J-24 only proves the directory renders cards/empty-state,
 * not the cover-photo promise specifically; no closer row exists for "browse
 * the member directory" itself. J-27 (follow from card) is the direct fit for
 * follow-people-and-connect-mutually. J-28 (mute from card) has no matching
 * row and is left unpinned. J-24-member is the direct fit for
 * cap-show-a-member-s-cover-photo-on-their-directory-card: it seeds a real
 * cover on A and asserts it PAINTS on A's card while a plain member (B)
 * browses the directory. All other tests use the authenticatedPage fixture
 * (admin owner only).
 */
test.describe('directory / members', () => {
    const B_LOGIN = process.env.BN_TEST_OTHER_USER ?? 'bn_e2e_target';

    test.beforeAll(async () => {
        const bId = await ensureUser(B_LOGIN, 'bn_e2e_target@example.com', 'BN E2E Target');
        expect(bId, `member "${B_LOGIN}" must exist`).toBeGreaterThan(0);
        await setUserMeta(bId, 'bn_onboarding_complete', '1');
    });
    test('members directory renders cards or empty state', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.members);
        await expect(page.locator(sel.app)).toBeVisible();

        // "cards OR empty state" is the real contract, and it has to be asserted
        // as one. The previous assertion was `count() >= 0`, which is true for
        // every possible page — including one where the selector matches nothing,
        // which is exactly what was happening. A spec that cannot fail is not a
        // spec; this one now fails if the directory renders neither.
        const cards = await page.locator(sel.memberCard).count();
        const empty = await page.locator(sel.memberDirectoryEmpty).isVisible().catch(() => false);
        expect(
            cards > 0 || empty,
            'members directory rendered neither member cards nor an empty state'
        ).toBeTruthy();
    });

    /**
     * J-27 follow from card.
     *
     * UPGRADED from WEAK → EFFECT (Wave-3). The old body asserted only that the
     * button's own innerText changed after the click — an optimistic flip that
     * the matrix flagged as passing even when the REST write silently 500s. It
     * now reads the real target member id off the card, follows through the UI,
     * and asserts the follow row was created in wp_bn_follows (server truth) after
     * a POST /users/{id}/follow. A DB read cannot be satisfied by an optimistic
     * label. The row is removed in a finally so reruns start clean.
     */
    test('J-27 follow from card creates the follow row (DB confirm)', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.members);
        const cards = await countMemberCards(page);
        if (cards === 0) {
            softSkip(testInfo, 'Directory is genuinely empty (empty state shown).');
            return;
        }

        // Pick a card the actor is NOT already following AND that exposes a Follow
        // control (the target may forbid follows via who_can_follow). Wait for the
        // grid to settle first — the directory server-renders page 1, then the
        // store may repaint, and either way the Follow button carries data-state.
        await expect(page.locator(sel.memberCard).first()).toBeVisible();
        const card = page.locator(FOLLOWABLE_CARD).first();
        if (!(await card.count())) {
            softSkip(testInfo, 'No directory card exposes a Follow control for a not-yet-followed member.');
            return;
        }

        const targetId = Number(await card.getAttribute('data-user-id'));
        expect(targetId, 'the followable card must carry a numeric data-user-id').toBeGreaterThan(0);

        const followerId = await userId(A_LOGIN);
        expect(followerId, 'actor must resolve to a user id').toBeGreaterThan(0);

        const p = await tablePrefix();
        const rowSql = `SELECT COUNT(*) FROM ${p}bn_follows WHERE follower_id=${followerId} AND following_id=${targetId}`;
        const clearSql = `DELETE FROM ${p}bn_follows WHERE follower_id=${followerId} AND following_id=${targetId}`;

        // Deterministic precondition: no pre-existing edge for this pair.
        await wp(['db', 'query', clearSql]);

        try {
            await Promise.all([
                page.waitForResponse(
                    (r) => r.url().includes(`/users/${targetId}/follow`) && r.request().method() === 'POST',
                    { timeout: 10_000 }
                ),
                card.locator(sel.memberCardFollow).first().click(),
            ]);

            expect(
                await dbCount(rowSql),
                'following from the card must create a wp_bn_follows row'
            ).toBe(1);
        } finally {
            await wp(['db', 'query', clearSql]);
        }
    });

    test('J-28 mute from card via more menu', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.members);
        const firstCard = page.locator(sel.memberCard).first();
        if ((await countMemberCards(page)) === 0) {
            softSkip(testInfo, 'Directory is genuinely empty (empty state shown).');
            return;
        }

        const more = firstCard.locator(sel.memberCardMenu).first();
        if (!(await more.isVisible().catch(() => false))) {
            softSkip(testInfo, 'No more-actions menu in card markup yet.');
            return;
        }
        await more.click();

        const mute = page.locator(`${sel.memberCardMute}, [role="menuitem"]:has-text("Mute")`).first();
        await expect(mute).toBeVisible({ timeout: 3_000 });
    });

    /**
     * J-24-member — a member's seeded cover photo actually PAINTS on their
     * directory card, walked by a plain MEMBER browsing the directory, at
     * phone width. MemberDirectoryController::shape_item() sends cover_url via
     * buddynext_user_cover_url() and member-card.php renders it as the card's
     * background-image; this asserts the rendered attribute, not just that the
     * card exists.
     */
    test('J-24-member a member sees a seeded cover photo render on a directory card (mobile 390px)', async ({ page }, testInfo) => {
        const targetLogin = A_LOGIN;
        const targetId = await userId(targetLogin);
        expect(targetId, `target "${targetLogin}" must exist`).toBeGreaterThan(0);
        const seedCover = 'http://buddynext-dev.local/wp-content/uploads/bn-e2e-directory-cover.jpg';
        const origCover = await getUserMeta(targetId, 'buddynext_cover_url');
        await setUserMeta(targetId, 'buddynext_cover_url', seedCover);

        try {
            await page.setViewportSize({ width: 390, height: 844 });
            await loginAs(page, B_LOGIN);
            await page.goto(urls.members);

            if ((await countMemberCards(page)) === 0) {
                softSkip(testInfo, 'Directory is genuinely empty (empty state shown).');
                return;
            }

            const card = page
                .locator(sel.memberCard)
                .filter({ has: page.locator(`a[href*="/members/${targetLogin}"]`) })
                .first();
            await expect(card, `the seeded member "${targetLogin}" must have a directory card`).toBeVisible({
                timeout: 5_000,
            });

            // Effect: the seeded cover URL is painted as the card's background-image
            // (member-card.php:255), not merely a tone gradient.
            const cover = card.locator('.bn-md-card__cover').first();
            await expect(cover).toBeVisible();
            const style = (await cover.getAttribute('style')) ?? '';
            expect(
                style,
                'the seeded cover photo must render as the card background-image'
            ).toContain(seedCover);
        } finally {
            if (origCover) {
                await setUserMeta(targetId, 'buddynext_cover_url', origCover);
            } else {
                await deleteUserMeta(targetId, 'buddynext_cover_url');
            }
        }
    });
});
