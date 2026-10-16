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
 * Covers: cap-group-content-into-spaces, cap-invite-people-to-a-space-with-a-shareable-link
 * Roles: admin, member
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

            // EFFECT: an invitation is not a membership yet: the invitee is not on the roster,
            // the head count is still the owner alone, and the private feed stays closed.
            const roster = await bnApi(page, 'GET', `/spaces/${space.id}/members?per_page=100`);
            expect(roster.status).toBe(200);
            const rows = (roster.data as Array<{ user_nicename?: string; user_login?: string }>) ?? [];
            expect(rows.some((r) => (r.user_nicename ?? r.user_login) === other), 'an invited (not accepted) user is listed as a member').toBe(false);
            expect(Number(((await getSpace(page, space.id)).data as SpaceRow).member_count)).toBe(1);
            const feed = await bnApi(invitee.page, 'GET', `/spaces/${space.id}/feed`);
            expect(feed.status, 'an invited (not accepted) user can read the private feed').toBe(403);
        } finally {
            await invitee.ctx.close();
            await deleteSpaceApi(page, space.id);
        }
    });
});
