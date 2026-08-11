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
 * J-632 — Transfer ownership to another member (C2).
 *
 * A second actor joins an open space; the owner transfers ownership to them
 * (POST /spaces/{id}/transfer-ownership). The new owner must already be an
 * active member (the endpoint 422s otherwise), so the ownership primitive moves
 * bn_spaces.owner_id and the role='owner' row together.
 *
 * EFFECT (not presence):
 *   - the space's owner_id becomes the new owner;
 *   - the new owner's OWN view reports membership_role 'owner' + can_manage true;
 *   - the previous owner is demoted (membership_role no longer 'owner').
 * Throwaway space + second context torn down in `finally`.
 */
test.describe('spaces / transfer ownership (J-632)', () => {
    const other = process.env.BN_TEST_OTHER_USER ?? 'alice';

    type MemberRow = { user_id: number; user_nicename?: string; user_login?: string };

    test('J-632 transferring ownership moves the owner role to the new member', async ({
        authenticatedPage: page,
        browser,
        baseURL,
    }) => {
        const stamp = Date.now().toString().slice(-8);
        const space = await createSpaceApi(page, { name: `E2E Transfer ${stamp}`, type: 'open' });
        const actor = await loginContextAs(browser, baseURL, other);

        try {
            // The member joins so they are eligible to receive ownership.
            const join = await bnApi(actor.page, 'POST', `/spaces/${space.id}/join`);
            expect(join.status, `join failed: ${JSON.stringify(join.data)}`).toBe(200);

            // Resolve the new owner's user id from the roster.
            const roster = await bnApi(page, 'GET', `/spaces/${space.id}/members?per_page=100`);
            expect(roster.status).toBe(200);
            const rows = (roster.data as MemberRow[]) ?? [];
            const mine = rows.find((r) => (r.user_nicename ?? r.user_login) === other);
            expect(mine, `member "${other}" not found in roster`).toBeTruthy();
            const uid = Number(mine!.user_id);

            // ── TRANSFER ───────────────────────────────────────────────────────
            const xfer = await bnApi(page, 'POST', `/spaces/${space.id}/transfer-ownership`, {
                new_owner_id: uid,
            });
            expect(xfer.status, `transfer failed: ${JSON.stringify(xfer.data)}`).toBe(200);
            expect((xfer.data as { transferred?: boolean }).transferred).toBe(true);

            // EFFECT 1: the space's owner_id moved.
            const g = await getSpace(page, space.id);
            expect(Number((g.data as SpaceRow).owner_id)).toBe(uid);

            // EFFECT 2: the new owner sees themselves as owner + manager.
            const newOwnerView = await getSpace(actor.page, space.id);
            expect((newOwnerView.data as SpaceRow).membership_role).toBe('owner');
            expect((newOwnerView.data as SpaceRow).can_manage).toBe(true);

            // EFFECT 3: the previous owner is no longer owner.
            expect((g.data as SpaceRow).membership_role ?? '').not.toBe('owner');
        } finally {
            await actor.ctx.close();
            // The admin still holds manage_options, so cleanup succeeds even though
            // ownership moved.
            await deleteSpaceApi(page, space.id);
        }
    });
});
