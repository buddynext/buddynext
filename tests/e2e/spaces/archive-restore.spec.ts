import { test, expect } from '../_fixtures/auth.fixture';
import { createSpaceApi, deleteSpaceApi, bnApi, getSpace, type SpaceRow } from '../_fixtures/spaces-rest';

/**
 * J-631 — Archive then restore a space (C2, counterpart pair).
 *
 * The owner archives a space (POST /spaces/{id}/archive) and restores it
 * (DELETE /spaces/{id}/archive).
 *
 * EFFECT (not presence): GET /spaces/{id} reports is_archived truthy after
 * archive and falsy after restore — the persisted column, not an optimistic
 * label. Throwaway space torn down in `finally`.
 */
test.describe('spaces / archive + restore (J-631)', () => {
    const isArchived = async (page: import('@playwright/test').Page, id: number): Promise<boolean> => {
        const res = await getSpace(page, id);
        expect(res.status, `get space failed: ${JSON.stringify(res.data)}`).toBe(200);
        return Boolean((res.data as SpaceRow).is_archived);
    };

    test('J-631 archive sets is_archived; restore clears it', async ({ authenticatedPage: page }) => {
        const stamp = Date.now().toString().slice(-8);
        const space = await createSpaceApi(page, { name: `E2E Archive ${stamp}`, type: 'open' });

        try {
            // Baseline: a fresh space is not archived.
            expect(await isArchived(page, space.id)).toBe(false);

            // ── ARCHIVE ────────────────────────────────────────────────────────
            const arch = await bnApi(page, 'POST', `/spaces/${space.id}/archive`);
            expect(arch.status, `archive failed: ${JSON.stringify(arch.data)}`).toBe(200);
            expect((arch.data as { archived?: boolean }).archived).toBe(true);
            expect(await isArchived(page, space.id)).toBe(true);

            // ── RESTORE (counterpart) ──────────────────────────────────────────
            const restore = await bnApi(page, 'DELETE', `/spaces/${space.id}/archive`);
            expect(restore.status, `restore failed: ${JSON.stringify(restore.data)}`).toBe(200);
            expect((restore.data as { archived?: boolean }).archived).toBe(false);
            expect(await isArchived(page, space.id)).toBe(false);
        } finally {
            await deleteSpaceApi(page, space.id);
        }
    });
});
