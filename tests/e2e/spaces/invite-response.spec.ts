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
 * J-662 / J-663 — Accept and decline a space invitation (invitee side).
 *
 * These are the invitee counterparts of the owner-side Invite (J-606). The owner
 * invites a seeded user to a private space (POST /spaces/{id}/invite), so the
 * invitee's membership_status becomes 'invited'. The invitee then:
 *   - ACCEPT  → POST /spaces/{id}/join   (the store's actions.acceptInvite path;
 *               join() promotes an 'invited' row straight to 'active').
 *   - DECLINE → POST /spaces/{id}/leave  (the store's actions.declineInvite path;
 *               leave() deletes the invited row).
 *
 * EFFECT (not presence), asserted in the INVITEE's own session:
 *   - accept  → membership_status === 'active' (and the owner roster lists them);
 *   - decline → membership_status is neither 'invited' nor 'active' (row gone).
 * Each test uses its own throwaway space + second context, torn down in `finally`.
 */
test.describe('spaces / invite response (J-662/J-663)', () => {
    const other = process.env.BN_TEST_OTHER_USER ?? 'alice';

    type MemberRow = { user_id: number; user_nicename?: string; user_login?: string };

    const invite = async (page: import('@playwright/test').Page, spaceId: number): Promise<void> => {
        const inv = await bnApi(page, 'POST', `/spaces/${spaceId}/invite`, { identifier: other });
        expect(inv.status, `invite failed: ${JSON.stringify(inv.data)}`).toBe(200);
        expect((inv.data as { invited?: boolean }).invited).toBe(true);
    };

    test('J-662 accepting an invitation makes the invitee an active member', async ({
        authenticatedPage: page,
        browser,
        baseURL,
    }) => {
        const stamp = Date.now().toString().slice(-8);
        const space = await createSpaceApi(page, { name: `E2E InvAccept ${stamp}`, type: 'private' });
        const invitee = await loginContextAs(browser, baseURL, other);

        try {
            await ensureOnboarded(invitee.page);

            // Owner invites → the invitee sees a standing invitation.
            await invite(page, space.id);
            const invited = await getSpace(invitee.page, space.id);
            expect((invited.data as SpaceRow).membership_status).toBe('invited');

            // Action under test: the invitee accepts.
            const accept = await bnApi(invitee.page, 'POST', `/spaces/${space.id}/join`);
            expect(accept.status, `accept failed: ${JSON.stringify(accept.data)}`).toBe(200);
            expect((accept.data as { joined?: boolean }).joined).toBe(true);

            // EFFECT: the invitee is now an active member (their own view)...
            const g = await getSpace(invitee.page, space.id);
            expect((g.data as SpaceRow).membership_status).toBe('active');

            // ...and the owner's roster now lists them.
            const rosterRes = await bnApi(page, 'GET', `/spaces/${space.id}/members?per_page=100`);
            expect(rosterRes.status).toBe(200);
            const rows = (rosterRes.data as MemberRow[]) ?? [];
            expect(rows.some((r) => (r.user_nicename ?? r.user_login) === other)).toBe(true);
        } finally {
            await invitee.ctx.close();
            await deleteSpaceApi(page, space.id);
        }
    });

    test('J-663 declining an invitation leaves the invitee a non-member', async ({
        authenticatedPage: page,
        browser,
        baseURL,
    }) => {
        const stamp = Date.now().toString().slice(-8);
        const space = await createSpaceApi(page, { name: `E2E InvDecline ${stamp}`, type: 'private' });
        const invitee = await loginContextAs(browser, baseURL, other);

        try {
            await ensureOnboarded(invitee.page);

            // Owner invites → the invitee sees a standing invitation.
            await invite(page, space.id);
            const invited = await getSpace(invitee.page, space.id);
            expect((invited.data as SpaceRow).membership_status).toBe('invited');

            // Action under test: the invitee declines.
            const decline = await bnApi(invitee.page, 'POST', `/spaces/${space.id}/leave`);
            expect(decline.status, `decline failed: ${JSON.stringify(decline.data)}`).toBe(200);

            // EFFECT: the invitation is cleared — neither invited nor active.
            const g = await getSpace(invitee.page, space.id);
            const status = (g.data as SpaceRow).membership_status ?? '';
            expect(status).not.toBe('invited');
            expect(status).not.toBe('active');

            // EFFECT: the owner's roster does not list them.
            const rosterRes = await bnApi(page, 'GET', `/spaces/${space.id}/members?per_page=100`);
            expect(rosterRes.status).toBe(200);
            const rows = (rosterRes.data as MemberRow[]) ?? [];
            expect(rows.some((r) => (r.user_nicename ?? r.user_login) === other)).toBe(false);
        } finally {
            await invitee.ctx.close();
            await deleteSpaceApi(page, space.id);
        }
    });
});
