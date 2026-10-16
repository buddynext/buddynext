import { test, expect } from '../_fixtures/auth.fixture';
import { urls } from '../_fixtures/selectors';
import { getSpace, createSpaceApi, deleteSpaceApi, bnApi, type SpaceRow } from '../_fixtures/spaces-rest';
import { dbCount, tablePrefix } from '../_fixtures/wp';

/**
 * J-610 — Delete a space (owner).
 *
 * Covers: cap-group-content-into-spaces
 * Roles: admin
 *
 * Member action: from Settings → Danger zone, open the delete-confirm modal, type
 * the exact space name to arm the button, and delete (actions.deleteSpaceConfirmed
 * → DELETE /spaces/{id} with the X-BN-Confirm-Space-Name header).
 *
 * EFFECT (not presence): after the delete the browser leaves for the spaces
 * directory, a REST GET of the space returns 404, and the directory carries no
 * link to its slug. A throwaway space is created for the test; a best-effort
 * REST delete in `finally` keeps reruns idempotent even if the UI path failed.
 */
test.describe('spaces / delete (J-610)', () => {
    const deleteOpen = '[data-wp-on--click="actions.openDeleteSpaceConfirm"]';
    const deleteModal = '[data-bn-modal="delete-space-confirm"]';
    const gate = '[data-bn-delete-gate]';
    const deleteSubmit = '[data-bn-delete-submit]';

    test('J-610 deleting a space removes it from the directory and the API', async ({
        authenticatedPage: page,
    }) => {
        const stamp = Date.now().toString().slice(-8);
        const name = `E2E Delete ${stamp}`;
        const space = await createSpaceApi(page, { name, type: 'open' });
        let deleted = false;

        try {
            await page.goto(`/spaces/${space.slug}/settings/?bn_stab=danger`);

            const opener = page.locator(deleteOpen).first();
            await expect(opener).toBeVisible({ timeout: 10_000 });
            await opener.click();

            const modal = page.locator(deleteModal);
            await expect(modal).toBeVisible({ timeout: 5_000 });

            // Type the exact name to arm the (initially disabled) delete button.
            await modal.locator(gate).fill(name);
            const submit = modal.locator(deleteSubmit);
            await expect(submit).toBeEnabled({ timeout: 5_000 });

            // EFFECT 1: the delete redirects back to the spaces directory.
            await Promise.all([
                page.waitForURL(/\/spaces\/?(\?.*)?$/, { timeout: 15_000 }),
                submit.click(),
            ]);

            // EFFECT 2: REST truth — the space is gone (404).
            await expect
                .poll(async () => (await getSpace(page, space.id)).status, { timeout: 10_000 })
                .toBe(404);
            deleted = true;
            // ...by slug too, the list route no longer answers it, and no membership rows
            // are left behind pointing at a space that does not exist.
            expect((await bnApi(page, 'GET', `/spaces/slug/${space.slug}`)).status, 'deleted space still resolves by slug').toBe(404);
            const list = await bnApi(page, 'GET', `/spaces?search=${encodeURIComponent(name)}&per_page=50`);
            expect(list.status).toBe(200);
            expect(((list.data as SpaceRow[]) ?? []).map((r) => Number(r.id))).not.toContain(space.id);
            const p = await tablePrefix();
            expect(
                await dbCount(`SELECT COUNT(*) FROM ${p}bn_space_members WHERE space_id = ${space.id}`),
                'membership rows orphaned by the delete'
            ).toBe(0);

            // EFFECT 3: the directory no longer links to the deleted slug.
            await page.goto(urls.spaces);
            await expect(page.locator(`a[href*="/spaces/${space.slug}/"]`)).toHaveCount(0, {
                timeout: 10_000,
            });
        } finally {
            if (!deleted) {
                await deleteSpaceApi(page, space.id);
            }
        }
    });
});
