import { test, expect } from '../_fixtures/auth.fixture';
import { createSpaceApi, deleteSpaceApi, bnApi, getSpace, type SpaceRow } from '../_fixtures/spaces-rest';

/**
 * J-630 — Space custom-field value save + clear (C2, counterpart pair).
 *
 * The space settings surface writes registered space-field VALUES through
 * POST /spaces/{id}/fields ({ fields: { key: value } }). This spec sets a value
 * on a writable text-capable field, then clears it — the add/remove counterpart
 * the coverage matrix flagged as the gap class that shipped the cover-remove bug.
 *
 * EFFECT (not presence): the value is read back from GET /spaces/{id} (the same
 * payload web + app render from), and after clearing it reads back empty. If no
 * writable text/textarea field is registered on this build, the test skips WITH A
 * REASON rather than false-passing. Throwaway space torn down in `finally`.
 */
test.describe('spaces / custom field value save + clear (J-630)', () => {
    type FieldDef = { key: string; type: string; value?: unknown };

    const readFields = async (
        page: import('@playwright/test').Page,
        id: number
    ): Promise<FieldDef[]> => {
        const res = await getSpace(page, id);
        expect(res.status, `get space failed: ${JSON.stringify(res.data)}`).toBe(200);
        return ((res.data as SpaceRow).fields as FieldDef[] | undefined) ?? [];
    };

    test('J-630 saving a space field value persists; clearing removes it', async ({
        authenticatedPage: page,
    }) => {
        const stamp = Date.now().toString().slice(-8);
        const space = await createSpaceApi(page, { name: `E2E Fields ${stamp}`, type: 'open' });

        try {
            // Pick a writable, text-capable field from THIS space's payload
            // (owner sees member-visibility fields). No hardcoded assumption about
            // which fields the build registers.
            const fields = await readFields(page, space.id);
            const target = fields.find((f) => f.type === 'textarea' || f.type === 'text');
            test.skip(
                !target,
                'no writable text/textarea space field registered on this build — cannot exercise the value save/clear seam'
            );
            const key = target!.key;
            const value = `e2e-field-${stamp}`;

            // ── SAVE (add) ─────────────────────────────────────────────────────
            const save = await bnApi(page, 'POST', `/spaces/${space.id}/fields`, {
                fields: { [key]: value },
            });
            expect(save.status, `save field failed: ${JSON.stringify(save.data)}`).toBe(200);
            // PHP serialises an empty error map as [] and a populated one as an object.
            const saveErrors = (save.data as { errors?: unknown }).errors;
            expect(
                Object.keys((saveErrors as Record<string, unknown>) ?? {}).length,
                `save reported field errors: ${JSON.stringify(saveErrors)}`
            ).toBe(0);

            // EFFECT: the value is present in the canonical space payload.
            const afterSave = await readFields(page, space.id);
            const savedRow = afterSave.find((f) => f.key === key);
            expect(String(savedRow?.value ?? '')).toBe(value);

            // ── CLEAR (remove counterpart) ─────────────────────────────────────
            const clear = await bnApi(page, 'POST', `/spaces/${space.id}/fields`, {
                fields: { [key]: '' },
            });
            expect(clear.status, `clear field failed: ${JSON.stringify(clear.data)}`).toBe(200);

            // EFFECT: the value is gone.
            const afterClear = await readFields(page, space.id);
            const clearedRow = afterClear.find((f) => f.key === key);
            expect(String(clearedRow?.value ?? '')).toBe('');
        } finally {
            await deleteSpaceApi(page, space.id);
        }
    });
});
