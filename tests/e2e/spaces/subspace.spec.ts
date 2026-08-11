import { test, expect } from '../_fixtures/auth.fixture';
import { createSpaceApi, deleteSpaceApi, bnApi, getSpace, type SpaceRow } from '../_fixtures/spaces-rest';

/**
 * J-623 — Create a sub-space (C4).
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
});
