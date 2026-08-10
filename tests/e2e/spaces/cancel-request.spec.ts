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
 * J-661 — Cancel own join request (requester). Counterpart of Request-to-join
 * (J-41): the requester withdrawing their OWN pending request before a mod acts.
 *
 * A second actor requests to join a private (request-to-join) space and lands in
 * 'pending'. They then cancel via POST /spaces/{id}/join/cancel (the endpoint the
 * "Requested — cancel" control calls).
 *
 * EFFECT (not presence):
 *   - the requester's OWN GET reports membership_status ≠ 'pending' (the row is
 *     gone, so it reads '');
 *   - the owner's pending-requests queue no longer lists them.
 * A cancel that 200s but leaves the pending row fails here. Throwaway space +
 * second context torn down in `finally`.
 */
test.describe('spaces / cancel own join request (J-661)', () => {
    const other = process.env.BN_TEST_OTHER_USER ?? 'alice';

    type PendingItem = { user_id: number };

    const pending = async (
        page: import('@playwright/test').Page,
        spaceId: number
    ): Promise<number[]> => {
        const res = await bnApi(page, 'GET', `/spaces/${spaceId}/pending-requests`);
        expect(res.status, `pending-requests failed: ${JSON.stringify(res.data)}`).toBe(200);
        return ((res.data as { items?: PendingItem[] }).items ?? []).map((i) => Number(i.user_id));
    };

    test('J-661 a requester cancelling their own request clears it on both sides', async ({
        authenticatedPage: page,
        browser,
        baseURL,
    }) => {
        const stamp = Date.now().toString().slice(-8);
        const space = await createSpaceApi(page, { name: `E2E Cancel ${stamp}`, type: 'private' });
        const actor = await loginContextAs(browser, baseURL, other);

        try {
            await ensureOnboarded(actor.page);

            // Setup: the requester asks to join → pending (their own view).
            const req = await bnApi(actor.page, 'POST', `/spaces/${space.id}/join`);
            expect(req.status, `request failed: ${JSON.stringify(req.data)}`).toBe(200);
            const asPending = await getSpace(actor.page, space.id);
            expect((asPending.data as SpaceRow).membership_status).toBe('pending');

            // Prove the owner sees the request first (resolve the requester uid).
            const queueBefore = await pending(page, space.id);
            expect(queueBefore.length, 'no pending request visible to the owner').toBeGreaterThan(0);
            const uid = queueBefore[0];

            // Action under test: the requester cancels their OWN request.
            const cancel = await bnApi(actor.page, 'POST', `/spaces/${space.id}/join/cancel`);
            expect(cancel.status, `cancel failed: ${JSON.stringify(cancel.data)}`).toBe(200);
            expect((cancel.data as { cancelled?: boolean }).cancelled).toBe(true);

            // EFFECT: the requester's own view is no longer pending.
            const afterMember = await getSpace(actor.page, space.id);
            expect((afterMember.data as SpaceRow).membership_status ?? '').not.toBe('pending');

            // EFFECT: the owner's pending queue no longer lists them.
            expect(await pending(page, space.id)).not.toContain(uid);
        } finally {
            await actor.ctx.close();
            await deleteSpaceApi(page, space.id);
        }
    });
});
