import { test, expect } from '../_fixtures/auth.fixture';
import { createSpaceApi, deleteSpaceApi } from '../_fixtures/spaces-rest';

/**
 * J-602 — Edit space permissions (who can post / who can invite).
 *
 * Member action: on the space Settings → Permissions panel, change the two
 * threshold selects and save via the panel's native settings POST.
 *
 * EFFECT (not presence): after the save the page reloads server-side and the
 * selects show the PERSISTED values (round-trip). who_can_post/who_can_invite are
 * space meta the REST space payload doesn't echo, so the reloaded form value IS
 * the truth source here — a save that no-ops fails this assertion. Throwaway
 * space, deleted in `finally`.
 */
test.describe('spaces / edit permissions (J-602)', () => {
    const whoPost = 'select[name="who_can_post"]';
    const whoInvite = 'select[name="who_can_invite"]';
    const savebarSubmit = '[data-bn-savebar-submit]';
    const savedNotice = '.bn-space-settings__notice[data-tone="success"]';

    test('J-602 changing who-can-post/invite persists across reload', async ({ authenticatedPage: page }) => {
        const stamp = Date.now().toString().slice(-8);
        const space = await createSpaceApi(page, { name: `E2E Perms ${stamp}`, type: 'open' });

        try {
            const settingsUrl = `/spaces/${space.slug}/settings/?bn_stab=permissions`;
            await page.goto(settingsUrl);

            // Defaults on a fresh space: members / mods. Move both to a new value.
            await expect(page.locator(whoPost)).toBeVisible({ timeout: 10_000 });
            await page.locator(whoPost).selectOption('mods');
            await page.locator(whoInvite).selectOption('owner');

            const submit = page.locator(savebarSubmit);
            await expect(submit).toBeVisible({ timeout: 5_000 });
            await Promise.all([
                page.waitForURL(/\/settings\//, { timeout: 15_000 }),
                submit.click(),
            ]);
            await page.waitForLoadState('load');
            await expect(page.locator(savedNotice)).toBeVisible({ timeout: 10_000 });

            // EFFECT: reloaded (server-rendered) selects carry the saved thresholds.
            await expect(page.locator(whoPost)).toHaveValue('mods');
            await expect(page.locator(whoInvite)).toHaveValue('owner');

            // Extra reload to prove it is stored, not just echoed by the POST render.
            await page.goto(settingsUrl);
            await expect(page.locator(whoPost)).toHaveValue('mods');
            await expect(page.locator(whoInvite)).toHaveValue('owner');
        } finally {
            await deleteSpaceApi(page, space.id);
        }
    });
});
