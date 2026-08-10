import { test, expect } from '../_fixtures/auth.fixture';
import { urls } from '../_fixtures/selectors';
import { getSpace, deleteSpaceApi, type SpaceRow } from '../_fixtures/spaces-rest';

/**
 * J-600 — Create a space (Spaces wave-1 effect spec).
 *
 * Member action: open the directory create modal, fill name/type/description,
 * submit. Driven through the REAL create UI (actions.submitCreate → POST /spaces).
 *
 * EFFECT (not presence): after submit the browser lands on the new space's home,
 * and a REST GET of that space proves it exists AND that the creator is its
 * owner+member (membership_role === 'owner', can_manage === true). A create that
 * silently 500s or fails to attach the owner row fails here. The throwaway space
 * is deleted in `finally`, so reruns are idempotent.
 */
test.describe('spaces / create (J-600)', () => {
    // Directory create modal selectors (scoped here, not in the shared dict).
    const trigger = '[data-bn-create-space-trigger]';
    const modal = '[data-bn-modal="create-space"]';
    const nameInput = '#bn-create-space-name';
    const typeSelect = '#bn-create-space-type';
    const descInput = '#bn-create-space-desc';
    const submit = '[data-bn-create-space-submit]';
    const heroActions = '.bn-sh-hero__actions[data-space-id]';

    test('J-600 creating a space lands on its home with the creator as owner', async ({
        authenticatedPage: page,
    }) => {
        const stamp = Date.now().toString().slice(-8);
        const name = `E2E Create ${stamp}`;
        let createdId = 0;

        try {
            await page.goto(urls.spaces);
            const cta = page.locator(trigger).first();
            await expect(cta).toBeVisible({ timeout: 10_000 });
            await cta.click();

            const dialog = page.locator(modal);
            await expect(dialog).toBeVisible({ timeout: 5_000 });

            await dialog.locator(nameInput).fill(name);
            await dialog.locator(typeSelect).selectOption('open');
            await dialog.locator(descInput).fill('created by J-600 e2e');
            await dialog.locator(submit).click();

            // EFFECT 1: the store redirects to the new space's home on success.
            await page.waitForURL(/\/spaces\/e2e-create-\d+\/?$/, { timeout: 15_000 });

            // The hero action cluster carries the canonical space id.
            const actions = page.locator(heroActions).first();
            await expect(actions).toBeVisible({ timeout: 10_000 });
            createdId = Number(await actions.getAttribute('data-space-id'));
            expect(createdId, 'space home did not expose a numeric space id').toBeGreaterThan(0);

            // EFFECT 2: REST truth — the space exists, carries the typed name, and
            // the creator is its owner + active member (viewer-relative flags).
            const res = await getSpace(page, createdId);
            expect(res.status, `GET /spaces/${createdId} failed: ${JSON.stringify(res.data)}`).toBe(200);
            const row = res.data as SpaceRow;
            expect(row.name).toBe(name);
            expect(row.type).toBe('open');
            expect(row.membership_role).toBe('owner');
            expect(row.membership_status).toBe('active');
            expect(row.can_manage).toBe(true);
        } finally {
            if (createdId > 0) {
                await deleteSpaceApi(page, createdId);
            }
        }
    });
});
