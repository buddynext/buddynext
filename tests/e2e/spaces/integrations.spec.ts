import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from '../_fixtures/precondition';
import { createSpaceApi, deleteSpaceApi, bnApi } from '../_fixtures/spaces-rest';

/**
 * J-673..J-675 — Per-space INTEGRATION toggles (C2), Wave-4 NEW, effect-based.
 *
 * The integration switches are persisted as core space fields
 * (CoreSpaceFields.php, section "integrations"):
 *   - `push_to_feed`  — mirror space posts into the site activity feed (default on)
 *   - `mvs_media_tab` — show the WPMediaVerse Media tab in the space (default off)
 * written through `POST /spaces/{id}/fields {fields:{…}}` and read back in the
 * canonical `GET /spaces/{id}` `fields[]` payload (boolean fields serialize as
 * JSON true/false via FieldType::rest_value). Each toggle is exercised in BOTH
 * directions and re-read on a fresh nonce cycle so a one-way write can't hide.
 *
 * `discussion` (Jetonomy) is NOT a `/fields` key — it is enabled by provisioning
 * a forum (`POST /spaces/{id}/forum`), which sets `jetonomy_forum_id` +
 * `discussion_enabled`. J-675 drives that and proves the linked-forum id
 * persisted; if Jetonomy cannot provision a forum on this harness it soft-skips
 * with the reason rather than false-passing.
 *
 * Throwaway space per test, deleted in `finally`.
 */
test.describe('spaces / integration toggles (J-673..J-675)', () => {
    type FieldRow = { key: string; value: unknown };

    /** Read one registered field's value (by key) from GET /spaces/{id}. */
    const fieldValue = async (
        page: import('@playwright/test').Page,
        spaceId: number,
        key: string
    ): Promise<unknown> => {
        const res = await bnApi(page, 'GET', `/spaces/${spaceId}`);
        expect(res.status, `getSpace failed: ${JSON.stringify(res.data)}`).toBe(200);
        const fields = ((res.data as { fields?: FieldRow[] }).fields ?? []) as FieldRow[];
        const row = fields.find((f) => f.key === key);
        expect(row, `field "${key}" not present in space fields payload`).toBeTruthy();
        return row!.value;
    };

    const setField = async (
        page: import('@playwright/test').Page,
        spaceId: number,
        key: string,
        value: string
    ): Promise<void> => {
        const res = await bnApi(page, 'POST', `/spaces/${spaceId}/fields`, {
            fields: { [key]: value },
        });
        expect(res.status, `save field "${key}"=${value} failed: ${JSON.stringify(res.data)}`).toBe(200);
    };

    test('J-673 push_to_feed toggle persists (both directions)', async ({ authenticatedPage: page }) => {
        const stamp = Date.now().toString().slice(-8);
        const space = await createSpaceApi(page, { name: `E2E PushFeed ${stamp}`, type: 'open' });

        try {
            // Default on.
            expect(await fieldValue(page, space.id, 'push_to_feed')).toBe(true);

            // Turn OFF → persists on a fresh GET/nonce cycle.
            await setField(page, space.id, 'push_to_feed', '0');
            expect(await fieldValue(page, space.id, 'push_to_feed')).toBe(false);

            // Turn back ON (counterpart) → persists.
            await setField(page, space.id, 'push_to_feed', '1');
            expect(await fieldValue(page, space.id, 'push_to_feed')).toBe(true);
        } finally {
            await deleteSpaceApi(page, space.id);
        }
    });

    test('J-674 mvs_media_tab toggle persists (both directions)', async ({ authenticatedPage: page }) => {
        const stamp = Date.now().toString().slice(-8);
        const space = await createSpaceApi(page, { name: `E2E MediaTab ${stamp}`, type: 'open' });

        try {
            // Default off.
            expect(await fieldValue(page, space.id, 'mvs_media_tab')).toBe(false);

            // Turn ON → persists (this is exactly the gate J-692 relies on).
            await setField(page, space.id, 'mvs_media_tab', '1');
            expect(await fieldValue(page, space.id, 'mvs_media_tab')).toBe(true);

            // A reload cycle (real page navigation) then re-read — survives it.
            await page.goto(`/spaces/${space.slug}/`, { waitUntil: 'domcontentloaded' });
            expect(await fieldValue(page, space.id, 'mvs_media_tab')).toBe(true);

            // Turn back OFF (counterpart) → persists.
            await setField(page, space.id, 'mvs_media_tab', '0');
            expect(await fieldValue(page, space.id, 'mvs_media_tab')).toBe(false);
        } finally {
            await deleteSpaceApi(page, space.id);
        }
    });

    test('J-675 discussion enable (forum provision) persists the linked forum', async ({
        authenticatedPage: page,
    }, testInfo) => {
        const stamp = Date.now().toString().slice(-8);
        const space = await createSpaceApi(page, { name: `E2E Discuss ${stamp}`, type: 'open' });

        try {
            // Baseline: no linked forum.
            expect(Number(await fieldValue(page, space.id, 'jetonomy_forum_id'))).toBe(0);

            // Enable discussions = provision (or link) a forum. Owner/admin path.
            const prov = await bnApi(page, 'POST', `/spaces/${space.id}/forum`);
            expect(prov.status, `forum provision failed: ${JSON.stringify(prov.data)}`).toBe(200);
            const forumId = Number((prov.data as { forum_id?: number }).forum_id ?? 0);

            if (forumId <= 0) {
                // Jetonomy admitted the request but couldn't create a forum on this
                // harness (e.g. the forum CPT/engine isn't seeded). Genuine missing
                // precondition — record it, don't fabricate a pass.
                softSkip(
                    testInfo,
                    'Jetonomy did not provision a forum (forum_id=0); discussions cannot be enabled on this harness.'
                );
                return;
            }

            // EFFECT: the linked-forum id is persisted on the space (readable in the
            // canonical fields payload) and survives a fresh GET/nonce cycle.
            expect(Number(await fieldValue(page, space.id, 'jetonomy_forum_id'))).toBe(forumId);

            // Idempotent enable: re-provision resolves to the SAME forum, not a new
            // one — the toggle is stable, not re-created on every call.
            const again = await bnApi(page, 'POST', `/spaces/${space.id}/forum`);
            expect(again.status).toBe(200);
            expect(Number((again.data as { forum_id?: number }).forum_id ?? 0)).toBe(forumId);
        } finally {
            await deleteSpaceApi(page, space.id);
        }
    });
});
