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
 * J-40 join open space + J-41 request to join a private space.
 *
 * UPGRADED to EFFECT-BASED (2026-08-10, Wave-3). The old specs clicked a card
 * button and asserted only that its own innerText flipped — the weakest possible
 * signal, passing even if the REST write silently 500'd while the JS optimistically
 * relabelled the control. They now drive a SECOND actor against a throwaway space
 * and assert the real server consequence: after Join the member's own REST GET
 * reports membership_status === 'active'; after Request it reports 'pending' AND
 * the owner's pending-requests queue lists them. Journey ids are unchanged.
 *
 * Throwaway space + second context torn down in `finally`.
 */
test.describe('spaces / join + request', () => {
    const other = process.env.BN_TEST_OTHER_USER ?? 'alice';

    test('J-40 joining an open space makes the member active (REST truth)', async ({
        authenticatedPage: page,
        browser,
        baseURL,
    }) => {
        const stamp = Date.now().toString().slice(-8);
        const space = await createSpaceApi(page, { name: `E2E Join ${stamp}`, type: 'open' });
        const actor = await loginContextAs(browser, baseURL, other);

        try {
            await ensureOnboarded(actor.page);

            // Baseline: not yet a member.
            const before = await getSpace(actor.page, space.id);
            expect((before.data as SpaceRow).membership_status ?? '').not.toBe('active');

            // Action under test: join the open space.
            const join = await bnApi(actor.page, 'POST', `/spaces/${space.id}/join`);
            expect(join.status, `join failed: ${JSON.stringify(join.data)}`).toBe(200);
            expect((join.data as { joined?: boolean }).joined).toBe(true);

            // EFFECT: the member's own view is now active.
            const after = await getSpace(actor.page, space.id);
            expect((after.data as SpaceRow).membership_status).toBe('active');
        } finally {
            await actor.ctx.close();
            await deleteSpaceApi(page, space.id);
        }
    });

    test('J-41 requesting a private space leaves the member pending (REST truth)', async ({
        authenticatedPage: page,
        browser,
        baseURL,
    }) => {
        const stamp = Date.now().toString().slice(-8);
        const space = await createSpaceApi(page, { name: `E2E Request ${stamp}`, type: 'private' });
        const actor = await loginContextAs(browser, baseURL, other);

        try {
            await ensureOnboarded(actor.page);

            const before = await getSpace(actor.page, space.id);
            expect((before.data as SpaceRow).membership_status ?? '').not.toBe('pending');

            // Action under test: request to join the private space.
            const req = await bnApi(actor.page, 'POST', `/spaces/${space.id}/join`);
            expect(req.status, `request failed: ${JSON.stringify(req.data)}`).toBe(200);
            expect((req.data as { requested?: boolean }).requested).toBe(true);

            // EFFECT: the member's own view is pending...
            const after = await getSpace(actor.page, space.id);
            expect((after.data as SpaceRow).membership_status).toBe('pending');

            // ...and the owner's pending-requests queue lists them.
            const queue = await bnApi(page, 'GET', `/spaces/${space.id}/pending-requests`);
            expect(queue.status).toBe(200);
            const items = (queue.data as { items?: { user_id: number }[] }).items ?? [];
            expect(items.length, 'owner does not see the pending request').toBeGreaterThan(0);
        } finally {
            await actor.ctx.close();
            await deleteSpaceApi(page, space.id);
        }
    });
});
