import { test, expect } from '../_fixtures/auth.fixture';
import {
    createSpaceApi,
    deleteSpaceApi,
    bnApi,
    getSpace,
    loginContextAs,
    type SpaceRow,
} from '../_fixtures/spaces-rest';

/**
 * J-633 — Change a member's role (C3): member -> moderator and back.
 *
 * A second actor joins an open space (role 'member'); the owner promotes them to
 * moderator (PUT /spaces/{id}/members/{uid}/role) then demotes them again.
 *
 * EFFECT (not presence): the new role is read back BOTH from the owner's roster
 * and from the target's own GET /spaces/{id} (membership_role), and the demotion
 * counterpart is verified too — a persisted role change, not an optimistic flip.
 * Throwaway space + second context torn down in `finally`.
 */
test.describe('spaces / change member role (J-633)', () => {
    const other = process.env.BN_TEST_OTHER_USER ?? 'alice';

    type MemberRow = { user_id: number; role?: string; user_nicename?: string; user_login?: string };

    const roleInRoster = async (
        page: import('@playwright/test').Page,
        spaceId: number,
        uid: number
    ): Promise<string> => {
        const res = await bnApi(page, 'GET', `/spaces/${spaceId}/members?per_page=100`);
        expect(res.status).toBe(200);
        const rows = (res.data as MemberRow[]) ?? [];
        const row = rows.find((r) => Number(r.user_id) === uid);
        expect(row, `member ${uid} missing from roster`).toBeTruthy();
        return String(row!.role ?? '');
    };

    test('J-633 promoting a member to moderator persists; demotion reverts it', async ({
        authenticatedPage: page,
        browser,
        baseURL,
    }) => {
        const stamp = Date.now().toString().slice(-8);
        const space = await createSpaceApi(page, { name: `E2E Role ${stamp}`, type: 'open' });
        const actor = await loginContextAs(browser, baseURL, other);

        try {
            const join = await bnApi(actor.page, 'POST', `/spaces/${space.id}/join`);
            expect(join.status, `join failed: ${JSON.stringify(join.data)}`).toBe(200);

            const roster = await bnApi(page, 'GET', `/spaces/${space.id}/members?per_page=100`);
            const rows = (roster.data as MemberRow[]) ?? [];
            const mine = rows.find((r) => (r.user_nicename ?? r.user_login) === other);
            expect(mine, `member "${other}" not found in roster`).toBeTruthy();
            const uid = Number(mine!.user_id);
            expect(String(mine!.role ?? '')).toBe('member');

            // ── PROMOTE ────────────────────────────────────────────────────────
            const promote = await bnApi(page, 'PUT', `/spaces/${space.id}/members/${uid}/role`, {
                role: 'moderator',
            });
            expect(promote.status, `promote failed: ${JSON.stringify(promote.data)}`).toBe(200);
            expect((promote.data as { role?: string }).role).toBe('moderator');

            // EFFECT: owner roster + target's own view both report moderator.
            expect(await roleInRoster(page, space.id, uid)).toBe('moderator');
            const asTarget = await getSpace(actor.page, space.id);
            expect((asTarget.data as SpaceRow).membership_role).toBe('moderator');

            // ── DEMOTE (counterpart) ───────────────────────────────────────────
            const demote = await bnApi(page, 'PUT', `/spaces/${space.id}/members/${uid}/role`, {
                role: 'member',
            });
            expect(demote.status, `demote failed: ${JSON.stringify(demote.data)}`).toBe(200);
            expect(await roleInRoster(page, space.id, uid)).toBe('member');
        } finally {
            await actor.ctx.close();
            await deleteSpaceApi(page, space.id);
        }
    });
});
