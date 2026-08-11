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
 * J-606 — Invite a member (owner).
 *
 * Member action: the owner invites a seeded user to a private space
 * (POST /spaces/{id}/invite {identifier}). The endpoint the hero Invite modal and
 * the settings invite form both call.
 *
 * EFFECT (not presence): the invitee, in their own session, sees the invitation —
 * a REST GET of the space as that user reports membership_status === 'invited'.
 * An invite that 200s but writes no row fails here. Throwaway space + second
 * context torn down in `finally`.
 */
test.describe('spaces / invite (J-606)', () => {
    const other = process.env.BN_TEST_OTHER_USER ?? 'alice';

    test('J-606 an owner invite creates a pending invitation the invitee sees', async ({
        authenticatedPage: page,
        browser,
        baseURL,
    }) => {
        const stamp = Date.now().toString().slice(-8);
        const space = await createSpaceApi(page, { name: `E2E Invite ${stamp}`, type: 'private' });
        const invitee = await loginContextAs(browser, baseURL, other);

        try {
            // Baseline: the invitee has no relationship to the space yet.
            let asInvitee = await getSpace(invitee.page, space.id);
            expect(asInvitee.status).toBe(200);
            expect((asInvitee.data as SpaceRow).membership_status ?? '').not.toBe('invited');

            // Action under test: owner invites by username.
            const inv = await bnApi(page, 'POST', `/spaces/${space.id}/invite`, { identifier: other });
            expect(inv.status, `invite failed: ${JSON.stringify(inv.data)}`).toBe(200);
            expect((inv.data as { invited?: boolean }).invited).toBe(true);

            // EFFECT: the invitee now sees a standing invitation.
            asInvitee = await getSpace(invitee.page, space.id);
            expect((asInvitee.data as SpaceRow).membership_status).toBe('invited');
        } finally {
            await invitee.ctx.close();
            await deleteSpaceApi(page, space.id);
        }
    });
});
