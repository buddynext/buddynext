import { test, expect } from '../_fixtures/auth.fixture';
import {
    getSpace,
    createSpaceApi,
    deleteSpaceApi,
    bnApi,
    ensureOnboarded,
    loginContextAs,
    type SpaceRow,
} from '../_fixtures/spaces-rest';

/**
 * J-660 — Remove a member (owner). Counterpart of Join (J-40): the owner-driven
 * removal that ends someone else's membership, distinct from a member's own Leave.
 *
 * A second actor joins an open space and becomes active. The owner resolves their
 * user id from the roster and removes them via DELETE /spaces/{id}/members/{uid}
 * (the exact endpoint the Members panel "Remove" button calls).
 *
 * EFFECT (not presence):
 *   - the removed member's OWN GET reports membership_status ≠ 'active' (the row
 *     is gone, so it reads '');
 *   - the owner's member roster no longer lists them.
 * A removal that 200s but leaves the row intact fails here. Throwaway space +
 * second context torn down in `finally`.
 */
test.describe('spaces / remove member (J-660)', () => {
    const other = process.env.BN_TEST_OTHER_USER ?? 'alice';

    type MemberRow = { user_id: number; user_nicename?: string; user_login?: string };

    const roster = async (
        page: import('@playwright/test').Page,
        spaceId: number
    ): Promise<MemberRow[]> => {
        const res = await bnApi(page, 'GET', `/spaces/${spaceId}/members?per_page=100`);
        expect(res.status, `roster failed: ${JSON.stringify(res.data)}`).toBe(200);
        return (res.data as MemberRow[]) ?? [];
    };

    test('J-660 an owner removing a member ends their membership on both sides', async ({
        authenticatedPage: page,
        browser,
        baseURL,
    }) => {
        const stamp = Date.now().toString().slice(-8);
        const space = await createSpaceApi(page, { name: `E2E Remove ${stamp}`, type: 'open' });
        const actor = await loginContextAs(browser, baseURL, other);

        try {
            await ensureOnboarded(actor.page);

            // Setup: the member joins and is active (their own view).
            const join = await bnApi(actor.page, 'POST', `/spaces/${space.id}/join`);
            expect(join.status, `join failed: ${JSON.stringify(join.data)}`).toBe(200);
            const joined = await getSpace(actor.page, space.id);
            expect((joined.data as SpaceRow).membership_status).toBe('active');

            // Resolve the member's user id from the owner's roster (and prove they
            // are listed before the removal).
            const before = await roster(page, space.id);
            const mine = before.find((r) => (r.user_nicename ?? r.user_login) === other);
            expect(mine, `member "${other}" not found in roster before removal`).toBeTruthy();
            const uid = Number(mine!.user_id);

            // Action under test: owner removes the member.
            const removed = await bnApi(page, 'DELETE', `/spaces/${space.id}/members/${uid}`);
            expect(removed.status, `remove failed: ${JSON.stringify(removed.data)}`).toBe(200);
            expect((removed.data as { removed?: boolean }).removed).toBe(true);

            // EFFECT: the member's own view is no longer active.
            const afterMember = await getSpace(actor.page, space.id);
            expect((afterMember.data as SpaceRow).membership_status ?? '').not.toBe('active');

            // EFFECT: the owner's roster no longer lists them.
            const after = await roster(page, space.id);
            expect(after.some((r) => Number(r.user_id) === uid)).toBe(false);
        } finally {
            await actor.ctx.close();
            await deleteSpaceApi(page, space.id);
        }
    });
});
