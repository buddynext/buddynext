import { test, expect } from '../_fixtures/auth.fixture';
import { createSpaceApi, deleteSpaceApi, bnApi } from '../_fixtures/spaces-rest';
import { urls } from '../_fixtures/selectors';

/**
 * J-622 — Per-space notification preference (C4).
 *
 * A member sets their notification preference for a space
 * (POST /spaces/{id}/notification-pref, prefs: all | mentions_only | none).
 *
 * EFFECT (not presence): the chosen value is read back by a fresh GET, and it
 * SURVIVES a full page reload (a new nonce, a new request cycle) — proving it
 * persisted server-side, not just in an optimistic client toggle. The counterpart
 * value ('none') is exercised too. Throwaway space torn down in `finally`.
 */
test.describe('spaces / notification pref (J-622)', () => {
    const getPref = async (page: import('@playwright/test').Page, id: number): Promise<string> => {
        const res = await bnApi(page, 'GET', `/spaces/${id}/notification-pref`);
        expect(res.status, `get pref failed: ${JSON.stringify(res.data)}`).toBe(200);
        return String((res.data as { pref?: string }).pref ?? '');
    };

    test('J-622 setting a per-space notification pref persists across reload', async ({
        authenticatedPage: page,
    }) => {
        const stamp = Date.now().toString().slice(-8);
        // Owner is an active member on create, so the membership row that stores
        // the pref exists.
        const space = await createSpaceApi(page, { name: `E2E Notif ${stamp}`, type: 'open' });

        try {
            // Set to a non-default value.
            const set = await bnApi(page, 'POST', `/spaces/${space.id}/notification-pref`, {
                pref: 'mentions_only',
            });
            expect(set.status, `set pref failed: ${JSON.stringify(set.data)}`).toBe(200);
            expect((set.data as { pref?: string }).pref).toBe('mentions_only');

            // EFFECT: read back immediately.
            expect(await getPref(page, space.id)).toBe('mentions_only');

            // EFFECT: survives a real page reload (new nonce / request cycle).
            await page.goto(urls.spaces, { waitUntil: 'domcontentloaded' });
            expect(await getPref(page, space.id)).toBe('mentions_only');

            // Counterpart value.
            const off = await bnApi(page, 'POST', `/spaces/${space.id}/notification-pref`, {
                pref: 'none',
            });
            expect(off.status, `set none failed: ${JSON.stringify(off.data)}`).toBe(200);
            expect(await getPref(page, space.id)).toBe('none');
        } finally {
            await deleteSpaceApi(page, space.id);
        }
    });
});
