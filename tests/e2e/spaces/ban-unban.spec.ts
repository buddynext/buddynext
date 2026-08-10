import { test, expect } from '../_fixtures/auth.fixture';
import {
    getSpace,
    createSpaceApi,
    deleteSpaceApi,
    bnApi,
    loginContextAs,
    type SpaceRow,
} from '../_fixtures/spaces-rest';

/**
 * J-609 — Ban then Unban a member (owner). Locks the F1 1.0.7 regression:
 * a space had a Ban action with no Unban counterpart (owner dead-end).
 *
 * A second actor joins an open space; the owner bans them, then unbans them.
 *
 * EFFECT (not presence):
 *   BAN  → the ban appears in GET /spaces/{id}/bans; the member's rejoin attempt
 *          is refused (403 space_banned); their membership_status is not 'active'.
 *   UNBAN→ the ban is gone from the list; the same member can rejoin and become an
 *          active member again.
 * Throwaway space + second context torn down in `finally`.
 */
test.describe('spaces / ban + unban (J-609)', () => {
    const other = process.env.BN_TEST_OTHER_USER ?? 'alice';

    type MemberRow = { user_id: number; user_nicename?: string; user_login?: string };
    type BanItem = { user_id: number };

    const banned = async (page: import('@playwright/test').Page, spaceId: number): Promise<number[]> => {
        const res = await bnApi(page, 'GET', `/spaces/${spaceId}/bans`);
        expect(res.status, `list bans failed: ${JSON.stringify(res.data)}`).toBe(200);
        return ((res.data as { items?: BanItem[] }).items ?? []).map((i) => Number(i.user_id));
    };

    test('J-609 ban removes + blocks rejoin; unban restores the ability', async ({
        authenticatedPage: page,
        browser,
        baseURL,
    }) => {
        const stamp = Date.now().toString().slice(-8);
        const space = await createSpaceApi(page, { name: `E2E Ban ${stamp}`, type: 'open' });
        const actor = await loginContextAs(browser, baseURL, other);

        try {
            // The member joins so there is someone to ban.
            const join = await bnApi(actor.page, 'POST', `/spaces/${space.id}/join`);
            expect(join.status, `join failed: ${JSON.stringify(join.data)}`).toBe(200);

            // Resolve the member's user id from the owner's roster.
            const roster = await bnApi(page, 'GET', `/spaces/${space.id}/members?per_page=100`);
            expect(roster.status).toBe(200);
            const rows = (roster.data as MemberRow[]) ?? [];
            const mine = rows.find((r) => (r.user_nicename ?? r.user_login) === other);
            expect(mine, `member "${other}" not found in roster`).toBeTruthy();
            const uid = Number(mine!.user_id);

            // ── BAN ──────────────────────────────────────────────────────────
            const ban = await bnApi(page, 'POST', `/spaces/${space.id}/bans`, { user_id: uid });
            expect(ban.status, `ban failed: ${JSON.stringify(ban.data)}`).toBe(200);

            // EFFECT: appears in the ban list.
            expect(await banned(page, space.id)).toContain(uid);

            // EFFECT: no longer an active member.
            const afterBan = await getSpace(actor.page, space.id);
            expect((afterBan.data as SpaceRow).membership_status ?? '').not.toBe('active');

            // EFFECT: rejoin is refused with a ban error.
            const rejoinBlocked = await bnApi(actor.page, 'POST', `/spaces/${space.id}/join`);
            expect(rejoinBlocked.status, `expected 403, got ${rejoinBlocked.status}: ${JSON.stringify(rejoinBlocked.data)}`).toBe(403);

            // ── UNBAN (counterpart) ────────────────────────────────────────────
            const unban = await bnApi(page, 'DELETE', `/spaces/${space.id}/bans/${uid}`);
            expect(unban.status, `unban failed: ${JSON.stringify(unban.data)}`).toBe(200);

            // EFFECT: gone from the ban list.
            expect(await banned(page, space.id)).not.toContain(uid);

            // EFFECT: the member can rejoin and is active again.
            const rejoin = await bnApi(actor.page, 'POST', `/spaces/${space.id}/join`);
            expect(rejoin.status, `rejoin after unban failed: ${JSON.stringify(rejoin.data)}`).toBe(200);
            const g = await getSpace(actor.page, space.id);
            expect((g.data as SpaceRow).membership_status).toBe('active');
        } finally {
            await actor.ctx.close();
            await deleteSpaceApi(page, space.id);
        }
    });
});
