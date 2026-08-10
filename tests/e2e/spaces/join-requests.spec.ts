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
 * J-607 / J-608 — Approve and decline a join request (owner, private space).
 *
 * A second actor requests to join a private space (POST /spaces/{id}/join →
 * status 'pending'). The owner sees the request in the pending queue and either
 * approves it (POST /members/{uid}/approve) or declines it
 * (POST /members/{uid}/decline).
 *
 * EFFECT (not presence):
 *   - approve → the requester's membership_status becomes 'active' (checked in
 *     the requester's OWN session).
 *   - decline → the requester is NOT a member and the pending queue no longer
 *     lists them.
 * Throwaway space + second context torn down in `finally` for each test.
 */
test.describe('spaces / join-request moderation (J-607/J-608)', () => {
    const other = process.env.BN_TEST_OTHER_USER ?? 'alice';

    type PendingItem = { user_id: number; display_name?: string };

    const requesterId = async (page: import('@playwright/test').Page, spaceId: number): Promise<number> => {
        const res = await bnApi(page, 'GET', `/spaces/${spaceId}/pending-requests`);
        expect(res.status, `pending-requests failed: ${JSON.stringify(res.data)}`).toBe(200);
        const items = (res.data as { items?: PendingItem[] }).items ?? [];
        expect(items.length, 'no pending request visible to the owner').toBeGreaterThan(0);
        const uid = Number(items[0].user_id);
        expect(uid).toBeGreaterThan(0);
        return uid;
    };

    test('J-607 approving a request makes the requester an active member', async ({
        authenticatedPage: page,
        browser,
        baseURL,
    }) => {
        const stamp = Date.now().toString().slice(-8);
        const space = await createSpaceApi(page, { name: `E2E Approve ${stamp}`, type: 'private' });
        const actor = await loginContextAs(browser, baseURL, other);

        try {
            // Requester asks to join → pending.
            const req = await bnApi(actor.page, 'POST', `/spaces/${space.id}/join`);
            expect(req.status, `request failed: ${JSON.stringify(req.data)}`).toBe(200);
            const pendingView = await getSpace(actor.page, space.id);
            expect((pendingView.data as SpaceRow).membership_status).toBe('pending');

            // Owner approves.
            const uid = await requesterId(page, space.id);
            const ok = await bnApi(page, 'POST', `/spaces/${space.id}/members/${uid}/approve`);
            expect(ok.status, `approve failed: ${JSON.stringify(ok.data)}`).toBe(200);

            // EFFECT: the requester is now an active member (their own view).
            const g = await getSpace(actor.page, space.id);
            expect((g.data as SpaceRow).membership_status).toBe('active');
        } finally {
            await actor.ctx.close();
            await deleteSpaceApi(page, space.id);
        }
    });

    test('J-608 declining a request rejects the requester', async ({
        authenticatedPage: page,
        browser,
        baseURL,
    }) => {
        const stamp = Date.now().toString().slice(-8);
        const space = await createSpaceApi(page, { name: `E2E Decline ${stamp}`, type: 'private' });
        const actor = await loginContextAs(browser, baseURL, other);

        try {
            const req = await bnApi(actor.page, 'POST', `/spaces/${space.id}/join`);
            expect(req.status, `request failed: ${JSON.stringify(req.data)}`).toBe(200);

            const uid = await requesterId(page, space.id);
            const no = await bnApi(page, 'POST', `/spaces/${space.id}/members/${uid}/decline`);
            expect(no.status, `decline failed: ${JSON.stringify(no.data)}`).toBe(200);

            // EFFECT: not a member...
            const g = await getSpace(actor.page, space.id);
            expect((g.data as SpaceRow).membership_status ?? '').not.toBe('active');

            // ...and the owner's pending queue is empty of them.
            const after = await bnApi(page, 'GET', `/spaces/${space.id}/pending-requests`);
            const items = (after.data as { items?: { user_id: number }[] }).items ?? [];
            expect(items.some((i) => Number(i.user_id) === uid)).toBe(false);
        } finally {
            await actor.ctx.close();
            await deleteSpaceApi(page, space.id);
        }
    });
});
