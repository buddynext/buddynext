import { test, expect } from '../_fixtures/auth.fixture';
import {
    createSpaceApi,
    deleteSpaceApi,
    bnApi,
    getSpace,
    loginContextAs,
    ensureOnboarded,
    type SpaceRow,
} from '../_fixtures/spaces-rest';

/**
 * J-623 — Create a sub-space (C4).
 *
 * Covers: cap-group-content-into-spaces, cap-archive-and-restore-a-space-or-create-a-sub-space-under-it
 * Roles: admin, member
 *
 * A manager of a parent space creates a child space (POST /spaces with
 * parent_id). SpaceService enforces the two-level depth limit; only a manager of
 * the parent may nest under it.
 *
 * EFFECT (not presence): the created child carries parent_id === parent, and it
 * shows up in GET /spaces/{parent}/subspaces. Both spaces torn down in `finally`
 * (child first, then parent).
 */
test.describe('spaces / sub-space create (J-623)', () => {
    const other = process.env.BN_TEST_OTHER_USER ?? 'alice';

    type SubspacesResp = { subspaces?: Array<{ id: number }>; total?: number };

    test('J-623 creating a sub-space nests it under the parent', async ({
        authenticatedPage: page,
    }) => {
        const stamp = Date.now().toString().slice(-8);
        const parent = await createSpaceApi(page, { name: `E2E Parent ${stamp}`, type: 'open' });
        let childId = 0;

        try {
            const res = await bnApi(page, 'POST', '/spaces', {
                name: `E2E Child ${stamp}`,
                type: 'open',
                parent_id: parent.id,
                description: 'e2e sub-space',
            });
            expect(res.status, `create sub-space failed: ${JSON.stringify(res.data)}`).toBe(201);
            const child = res.data as SpaceRow;
            childId = Number(child.id);
            expect(childId, 'child carried no id').toBeGreaterThan(0);

            // EFFECT 1: the child is bound to the parent (REST truth, viewer-relative).
            const g = await getSpace(page, childId);
            expect(g.status).toBe(200);
            expect(Number((g.data as SpaceRow).parent_id)).toBe(parent.id);

            // EFFECT 2: the parent lists the child among its sub-spaces.
            const subs = await bnApi(page, 'GET', `/spaces/${parent.id}/subspaces`);
            expect(subs.status, `subspaces failed: ${JSON.stringify(subs.data)}`).toBe(200);
            const ids = ((subs.data as SubspacesResp).subspaces ?? []).map((s) => Number(s.id));
            expect(ids).toContain(childId);
        } finally {
            if (childId > 0) {
                await deleteSpaceApi(page, childId);
            }
            await deleteSpaceApi(page, parent.id);
        }
    });

    test('J-623 member: a private parent refuses its sub-space list to a non-member and reveals it once joined', async ({
        authenticatedPage: page,
        browser,
        baseURL,
    }) => {
        const stamp = Date.now().toString().slice(-8);
        const parent = await createSpaceApi(page, { name: `E2E ParentMember ${stamp}`, type: 'private' });
        const actor = await loginContextAs(browser, baseURL, other);
        let childId = 0;

        try {
            await ensureOnboarded(actor.page);

            const child = await bnApi(page, 'POST', '/spaces', {
                name: `E2E ChildMember ${stamp}`,
                type: 'open',
                parent_id: parent.id,
                description: 'e2e sub-space (member leg)',
            });
            expect(child.status, `create sub-space failed: ${JSON.stringify(child.data)}`).toBe(201);
            childId = Number((child.data as SpaceRow).id);
            expect(childId, 'child carried no id').toBeGreaterThan(0);

            // Non-member: a private parent's structure is CONTENT, refused outright
            // (403), not merely an empty list.
            const before = await bnApi(actor.page, 'GET', `/spaces/${parent.id}/subspaces`);
            expect(before.status, 'a non-member should not list a private parent\'s sub-spaces').toBe(403);

            // Join (request + owner approve — same round trip as J-607).
            const req = await bnApi(actor.page, 'POST', `/spaces/${parent.id}/join`);
            expect(req.status, `request failed: ${JSON.stringify(req.data)}`).toBe(200);
            const queue = await bnApi(page, 'GET', `/spaces/${parent.id}/pending-requests`);
            const uid = Number(
                ((queue.data as { items?: { user_id: number }[] }).items ?? [])[0]?.user_id ?? 0
            );
            expect(uid, 'owner does not see the pending request').toBeGreaterThan(0);
            const approve = await bnApi(page, 'POST', `/spaces/${parent.id}/members/${uid}/approve`);
            expect(approve.status, `approve failed: ${JSON.stringify(approve.data)}`).toBe(200);

            // EFFECT: a joined member now sees the child sub-space.
            const after = await bnApi(actor.page, 'GET', `/spaces/${parent.id}/subspaces`);
            expect(after.status, `subspaces failed for a member: ${JSON.stringify(after.data)}`).toBe(200);
            const ids = ((after.data as SubspacesResp).subspaces ?? []).map((s) => Number(s.id));
            expect(ids, 'joined member did not see the sub-space').toContain(childId);

            // Phone width: the member's own view of the parent is unlocked too
            // (the content gate is gone), not just the REST list.
            await actor.page.setViewportSize({ width: 390, height: 844 });
            await actor.page.goto(`/spaces/${parent.slug}/`, { waitUntil: 'domcontentloaded' });
            await expect(
                actor.page.locator('.bn-sh-gate'),
                'content gate still showing for a joined member (390px)'
            ).toHaveCount(0);
        } finally {
            await actor.ctx.close();
            if (childId > 0) {
                await deleteSpaceApi(page, childId);
            }
            await deleteSpaceApi(page, parent.id);
        }
    });
});
