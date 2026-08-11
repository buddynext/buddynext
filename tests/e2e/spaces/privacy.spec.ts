import { test, expect } from '../_fixtures/auth.fixture';
import { getSpace, createSpaceApi, deleteSpaceApi, type SpaceRow } from '../_fixtures/spaces-rest';

/**
 * J-601 — Edit space privacy (public <-> private).
 *
 * Member action: on the space Settings → Privacy panel, change the "Space
 * visibility" select and save it through the space's native settings POST (the
 * same write the sticky save bar performs).
 *
 * EFFECT (not presence): after each save the page reloads server-side, so the
 * select reflects the PERSISTED value (round-trip), and a REST GET of the space
 * reports the new `type`. Both directions are exercised (open→private→open) so a
 * one-way write bug can't hide. Throwaway space, deleted in `finally`.
 */
test.describe('spaces / edit privacy (J-601)', () => {
    const typeSelect = 'select[name="space_type"]';
    const settingsForm = 'form[data-bn-settings-general-form]';
    const savebarSubmit = '[data-bn-savebar-submit]';
    const savedNotice = '.bn-space-settings__notice[data-tone="success"]';

    const saveVisibility = async (page: import('@playwright/test').Page, value: string): Promise<void> => {
        const select = page.locator(typeSelect);
        await expect(select).toBeVisible({ timeout: 10_000 });
        await select.selectOption(value);
        // The change event marks the settings form dirty and reveals the save bar.
        const submit = page.locator(savebarSubmit);
        await expect(submit).toBeVisible({ timeout: 5_000 });
        await Promise.all([
            page.waitForURL(/\/settings\//, { timeout: 15_000 }),
            submit.click(),
        ]);
        await page.waitForLoadState('load');
        await expect(page.locator(savedNotice)).toBeVisible({ timeout: 10_000 });
        void settingsForm;
    };

    test('J-601 changing visibility persists across reload and in REST', async ({ authenticatedPage: page }) => {
        const stamp = Date.now().toString().slice(-8);
        const space = await createSpaceApi(page, { name: `E2E Privacy ${stamp}`, type: 'open' });

        try {
            const settingsUrl = `/spaces/${space.slug}/settings/?bn_stab=privacy`;

            // open → private.
            await page.goto(settingsUrl);
            await expect(page.locator(typeSelect)).toHaveValue('open');
            await saveVisibility(page, 'private');
            await expect(page.locator(typeSelect)).toHaveValue('private'); // reloaded value
            let res = await getSpace(page, space.id);
            expect(res.status).toBe(200);
            expect((res.data as SpaceRow).type).toBe('private');

            // private → back to open (counterpart direction).
            await saveVisibility(page, 'open');
            await expect(page.locator(typeSelect)).toHaveValue('open');
            res = await getSpace(page, space.id);
            expect((res.data as SpaceRow).type).toBe('open');
        } finally {
            await deleteSpaceApi(page, space.id);
        }
    });
});
