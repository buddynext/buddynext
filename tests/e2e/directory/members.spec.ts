import { test, expect } from '../_fixtures/auth.fixture';
import { loginAs } from '../_fixtures/actor';
import { softSkip } from "../_fixtures/precondition";
import { sel, urls } from '../_fixtures/selectors';
import { userId, ensureUser, setUserMeta, getUserMeta, deleteUserMeta, tablePrefix, dbCount, wp } from '../_fixtures/wp';
import { bnApi } from '../_fixtures/spaces-rest';
import { listOf, restList } from '../_fixtures/effects';
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
// Member rows are keyed `user_id` on GET /members (effects.idsOf reads id / ID / post_id).
function memberIds(data: unknown): number[] {
    return listOf(data).map((row) => Number(row.user_id ?? row.id ?? 0)).filter((n) => n > 0);
}

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
        const bId = await ensureUser(B_LOGIN, 'bn_e2e_target@example.com', 'Jordan Ellis');
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

        // EFFECT: the grid is the directory route's answer for the same (default) query:
        // both empty or both populated, and no card for a member the route does not list.
        const rest = await restList(page, '/members?per_page=50');
        expect(rest.status, 'GET /members').toBe(200);
        const routeIds = memberIds(rest.data);
        expect(cards > 0, `screen shows ${cards} cards, route answers ${routeIds.length} members`).toBe(routeIds.length > 0);
        const shown = (await page.locator('.bn-md-card[data-user-id]').evaluateAll((els) =>
            els.map((el) => Number(el.getAttribute('data-user-id')))
        )) as number[];
        if (shown.length > 0 && shown.length <= 24) {
            expect(shown.filter((id) => !routeIds.includes(id)), 'cards on screen that GET /members does not list').toEqual([]);
        }
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
            const [followResponse] = await Promise.all([
                page.waitForResponse(
                    (r) => r.url().includes(`/users/${targetId}/follow`) && r.request().method() === 'POST',
                    { timeout: 10_000 }
                ),
                card.locator(sel.memberCardFollow).first().click(),
            ]);
            expect(
                followResponse.status(),
                `the follow call must succeed: ${await followResponse.text().catch(() => '')}`
            ).toBeLessThan(300);

            expect(
                await dbCount(rowSql),
                'following from the card must create a wp_bn_follows row'
            ).toBe(1);

            // EFFECT: the follow-status route agrees with the row...
            const status = await bnApi(page, 'GET', `/users/${targetId}/follow/status`);
            expect(status.status, 'GET follow/status').toBe(200);
            expect((status.data as { is_following?: boolean }).is_following, 'follow/status after following from the card').toBe(true);

            // ...and a reload paints that member's card as followed (server-rendered state).
            await page.goto(urls.members);
            const reloaded = page.locator(`.bn-md-card[data-user-id="${targetId}"] ${sel.memberCardFollow}`).first();
            if (await reloaded.count()) {
                await expect(reloaded).toHaveAttribute('data-state', 'following');
            }
        } finally {
            await wp(['db', 'query', clearSql]);
        }
    });

    test('J-28 mute from card via more menu', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.members);
        if ((await countMemberCards(page)) === 0) {
            softSkip(testInfo, 'Directory is genuinely empty (empty state shown).');
            return;
        }

        // The viewer's own card has no kebab (member-card.php:207), so take the first card
        // that has one instead of soft-skipping whenever the viewer happens to sort first.
        const firstWithMenu = page.locator(`.bn-md-card:has(${sel.memberCardMenu})`).first();
        await expect(firstWithMenu, 'no directory card exposes the more-actions menu').toBeVisible({ timeout: 10_000 });
        const targetId = Number(await firstWithMenu.getAttribute('data-user-id'));
        expect(targetId, 'the card must carry a numeric data-user-id').toBeGreaterThan(0);
        // Pin the card by member id so a reload cannot re-target another member.
        const card = page.locator(`.bn-md-card[data-user-id="${targetId}"]`).first();

        /** Whether the viewer's muted list (the route the settings page reads) holds the target. */
        const isMuted = async (): Promise<boolean> => {
            const r = await bnApi(page, 'GET', '/me/muted');
            expect(r.status, 'GET /me/muted').toBe(200);
            return ((r.data as { ids?: Array<number | string> }).ids ?? []).map(Number).includes(targetId);
        };
        // The mute item is the menu button bound to actions.toggleMute (member-card.php:232-237);
        // the shared `.bn-md-card__mute` class does not exist in the markup.
        const muteItem = card.locator('[role="menuitem"][data-wp-on--click="actions.toggleMute"]');
        const clickMute = async (method: 'POST' | 'DELETE'): Promise<void> => {
            if (!(await muteItem.isVisible().catch(() => false))) {
                await card.locator(sel.memberCardMenu).first().click();
            }
            await expect(muteItem).toBeVisible({ timeout: 3_000 });
            const [res] = await Promise.all([
                page.waitForResponse(
                    (r) => r.url().includes(`/users/${targetId}/mute`) && r.request().method() === method,
                    { timeout: 10_000 }
                ),
                muteItem.click(),
            ]);
            expect(res.status(), `${method} /users/${targetId}/mute`).toBeLessThan(300);
        };

        // Deterministic precondition: not muted.
        await bnApi(page, 'DELETE', `/users/${targetId}/mute`);
        expect(await isMuted()).toBe(false);
        await page.goto(urls.members);

        try {
            await card.locator(sel.memberCardMenu).first().click();
            await expect(muteItem).toBeVisible({ timeout: 3_000 });
            await expect(muteItem).toHaveText(/^\s*Mute\s*$/);

            // Action under test: Mute from the card menu.
            await clickMute('POST');
            // EFFECT: the member is on the viewer's muted list, and the item now offers Unmute.
            await expect.poll(isMuted, { timeout: 5_000 }).toBe(true);
            await expect(muteItem).toHaveText(/Unmute/);

            // Counterpart: Unmute from the same menu.
            await clickMute('DELETE');
            await expect.poll(isMuted, { timeout: 5_000 }).toBe(false);
        } finally {
            await bnApi(page, 'DELETE', `/users/${targetId}/mute`).catch(() => undefined);
        }
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

            // Default sort is "newest" (per MemberDirectoryController) and the seeded
            // target here is often the longest-registered account on a seeded site
            // (e.g. admin, user id 1) - it can sit on the last page of 130+ members,
            // never rendered on page 1. Search for it by login so the assertion below
            // proves the cover renders on the actual card, not on "whatever card the
            // default sort happens to put first". The directory searches what members
            // see, the display name, which need not equal the login (an owner who
            // renames the admin account to "Community Team" is not found by "admin").
            if ((await card.count()) === 0) {
                const input = page.locator(sel.directorySearch).first();
                if (await input.isVisible().catch(() => false)) {
                    const shownName = (await wp(['user', 'get', String(targetId), '--field=display_name'])).trim();
                    await input.fill(shownName || targetLogin);
                    await expect.poll(() => card.count(), { timeout: 5_000 }).toBeGreaterThan(0);
                }
            }

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

            // EFFECT: the card paints what the directory route sends this viewer for that member.
            const shownName = (await wp(['user', 'get', String(targetId), '--field=display_name'])).trim();
            const rest = await restList(page, `/members?search=${encodeURIComponent(shownName || targetLogin)}&per_page=50`);
            expect(rest.status, 'GET /members?search=').toBe(200);
            const row = rest.items.find((r) => Number(r.user_id) === targetId);
            expect(row, `GET /members does not list "${targetLogin}" for a member viewer`).toBeTruthy();
            expect(String(row?.cover_url ?? ''), 'route cover_url vs the seeded cover').toBe(seedCover);
        } finally {
            if (origCover) {
                await setUserMeta(targetId, 'buddynext_cover_url', origCover);
            } else {
                await deleteUserMeta(targetId, 'buddynext_cover_url');
            }
        }
    });
});
