import { test, expect } from '../_fixtures/auth.fixture';
import {
    createSpaceApi,
    deleteSpaceApi,
    bnApi,
    loginContextAs,
    ensureOnboarded,
} from '../_fixtures/spaces-rest';

/**
 * J-640..J-643 — Spaces directory listing controls (C1).
 *
 * The Wave-1 matrix left Scope (All/My), Sort, Include-sub-spaces and Pagination
 * MISSING. These drive the REAL list endpoint (GET /spaces) the reactive
 * directory store calls, and assert the LISTING actually changes — not that a
 * control is present.
 *
 * Each row is an id; a bare-array response and the paginated `{ items }` wrapper
 * are both normalised by `ids()`.
 */
test.describe('spaces / directory listing controls (J-640..J-643)', () => {
    const other = process.env.BN_TEST_OTHER_USER ?? 'alice';

    type Row = { id: number; name?: string };

    /** Normalise a /spaces response (bare array OR { items: [...] }) to ids. */
    const ids = (data: unknown): number[] => {
        const rows = Array.isArray(data)
            ? (data as Row[])
            : ((data as { items?: Row[] }).items ?? []);
        return rows.map((r) => Number(r.id));
    };
    const rowsOf = (data: unknown): Row[] =>
        Array.isArray(data) ? (data as Row[]) : ((data as { items?: Row[] }).items ?? []);

    test('J-640 scope All vs My changes which spaces the viewer sees', async ({
        authenticatedPage: page,
        browser,
        baseURL,
    }) => {
        const stamp = Date.now().toString().slice(-8);
        const spaceA = await createSpaceApi(page, { name: `E2E ScopeA ${stamp}`, type: 'open' });
        const spaceB = await createSpaceApi(page, { name: `E2E ScopeB ${stamp}`, type: 'open' });
        const actor = await loginContextAs(browser, baseURL, other);

        try {
            await ensureOnboarded(actor.page);
            // The viewer joins ONLY spaceA.
            const join = await bnApi(actor.page, 'POST', `/spaces/${spaceA.id}/join`);
            expect(join.status, `join failed: ${JSON.stringify(join.data)}`).toBe(200);

            // "My" scope: contains the joined space, excludes the un-joined one.
            const mine = await bnApi(actor.page, 'GET', '/spaces?mine=1&per_page=50');
            expect(mine.status, `mine list failed: ${JSON.stringify(mine.data)}`).toBe(200);
            const mineIds = ids(mine.data);
            expect(mineIds, 'My scope should include the joined space').toContain(spaceA.id);
            expect(mineIds, 'My scope should NOT include the un-joined space').not.toContain(spaceB.id);

            // "All" scope: both open spaces are discoverable.
            const all = await bnApi(actor.page, 'GET', '/spaces?orderby=newest&per_page=50');
            expect(all.status).toBe(200);
            const allIds = ids(all.data);
            expect(allIds).toContain(spaceA.id);
            expect(allIds, 'All scope should surface the un-joined space').toContain(spaceB.id);
        } finally {
            await actor.ctx.close();
            await deleteSpaceApi(page, spaceA.id);
            await deleteSpaceApi(page, spaceB.id);
        }
    });

    test('J-641 sort reorders the listing (alphabetical asc vs desc)', async ({
        authenticatedPage: page,
    }) => {
        // Raw orderby=name honours the order param (the 'alphabetical' alias would
        // force ASC and defeat the reversal check).
        const asc = await bnApi(page, 'GET', '/spaces?orderby=name&order=ASC&per_page=50');
        expect(asc.status).toBe(200);
        const desc = await bnApi(page, 'GET', '/spaces?orderby=name&order=DESC&per_page=50');
        expect(desc.status).toBe(200);

        const ascRows = rowsOf(asc.data);
        const descRows = rowsOf(desc.data);
        test.skip(ascRows.length < 2, 'fewer than 2 spaces in the directory — cannot assert ordering');

        const names = ascRows.map((r) => String(r.name ?? '').toLowerCase());
        // EFFECT 1: the ASC window is genuinely non-decreasing by name.
        for (let i = 1; i < names.length; i++) {
            expect(
                names[i] >= names[i - 1],
                `alphabetical ASC not ordered at ${i}: "${names[i - 1]}" then "${names[i]}"`
            ).toBeTruthy();
        }
        // EFFECT 2: DESC changes the head of the listing (sort is actually applied).
        expect(Number(ascRows[0].id)).not.toBe(Number(descRows[0].id));
    });

    test('J-642 include-subspaces toggle adds/removes child spaces', async ({
        authenticatedPage: page,
    }) => {
        const stamp = Date.now().toString().slice(-8);
        const parent = await createSpaceApi(page, { name: `E2E SubDir ${stamp}`, type: 'open' });
        let childId = 0;

        try {
            const create = await bnApi(page, 'POST', '/spaces', {
                name: `E2E SubDirChild ${stamp}`,
                type: 'open',
                parent_id: parent.id,
            });
            expect(create.status, `child create failed: ${JSON.stringify(create.data)}`).toBe(201);
            childId = Number((create.data as { id: number }).id);
            expect(childId).toBeGreaterThan(0);

            // Default directory is roots-only: the child is hidden.
            const roots = await bnApi(page, 'GET', '/spaces?orderby=newest&per_page=50');
            expect(roots.status).toBe(200);
            expect(ids(roots.data), 'child must be hidden without the toggle').not.toContain(childId);

            // With include_subspaces the child surfaces.
            const withSubs = await bnApi(
                page,
                'GET',
                '/spaces?orderby=newest&per_page=50&include_subspaces=1'
            );
            expect(withSubs.status).toBe(200);
            expect(ids(withSubs.data), 'child must appear with the toggle on').toContain(childId);
        } finally {
            if (childId > 0) {
                await deleteSpaceApi(page, childId);
            }
            await deleteSpaceApi(page, parent.id);
        }
    });

    test('J-643 pagination returns disjoint pages', async ({ authenticatedPage: page }) => {
        const p1 = await bnApi(page, 'GET', '/spaces?paginate=1&per_page=1&page=1&orderby=newest');
        expect(p1.status, `page 1 failed: ${JSON.stringify(p1.data)}`).toBe(200);
        const meta1 = p1.data as { items?: Row[]; total_pages?: number; page?: number };
        test.skip(
            (meta1.total_pages ?? 0) < 2,
            'fewer than 2 pages of spaces at per_page=1 — cannot assert pagination'
        );
        expect(meta1.page).toBe(1);
        const page1Ids = ids(p1.data);
        expect(page1Ids.length).toBe(1);

        const p2 = await bnApi(page, 'GET', '/spaces?paginate=1&per_page=1&page=2&orderby=newest');
        expect(p2.status).toBe(200);
        const meta2 = p2.data as { page?: number };
        expect(meta2.page).toBe(2);
        const page2Ids = ids(p2.data);
        expect(page2Ids.length).toBe(1);

        // EFFECT: page 2 is a different space than page 1.
        expect(page2Ids[0]).not.toBe(page1Ids[0]);
    });
});
