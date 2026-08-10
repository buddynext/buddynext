import { test, expect } from '../_fixtures/auth.fixture';
import {
    getSpace,
    createSpaceApi,
    deleteSpaceApi,
    bnUploadImage,
    bnApi,
    type SpaceRow,
} from '../_fixtures/spaces-rest';

/**
 * J-604 — Space avatar upload + remove (counterpart pair).
 *
 * Member action: owner uploads a space avatar (POST /spaces/{id}/avatar), then
 * removes it (DELETE /spaces/{id}/avatar).
 *
 * EFFECT (not presence): REST GET reports avatar_url set after upload and cleared
 * after remove, AND the server-rendered hero emblem renders an <img> after upload
 * and falls back to the non-image emblem after remove (asserted after reload).
 * Throwaway space, deleted in `finally`.
 */
test.describe('spaces / avatar upload + remove (J-604)', () => {
    const heroEmblemImg = '.bn-sh-hero__emblem img';

    test('J-604 avatar upload shows on the hero; remove reverts it', async ({ authenticatedPage: page }) => {
        const stamp = Date.now().toString().slice(-8);
        const space = await createSpaceApi(page, { name: `E2E Avatar ${stamp}`, type: 'open' });

        try {
            // Baseline: no avatar.
            let res = await getSpace(page, space.id);
            expect(res.status).toBe(200);
            expect((res.data as SpaceRow).avatar_url ?? '').toBe('');

            // UPLOAD.
            const up = await bnUploadImage(page, `/spaces/${space.id}/avatar`);
            expect(up.status, `avatar upload failed: ${JSON.stringify(up.data)}`).toBe(200);

            res = await getSpace(page, space.id);
            expect((res.data as SpaceRow).avatar_url ?? '', 'avatar_url not set after upload').not.toBe('');

            // EFFECT on the rendered hero: the emblem now renders an <img>.
            await page.goto(`/spaces/${space.slug}/`);
            await expect(page.locator(heroEmblemImg)).toBeVisible({ timeout: 10_000 });

            // REMOVE (counterpart).
            const del = await bnApi(page, 'DELETE', `/spaces/${space.id}/avatar`);
            expect(del.status, `avatar remove failed: ${JSON.stringify(del.data)}`).toBe(200);

            res = await getSpace(page, space.id);
            expect((res.data as SpaceRow).avatar_url ?? '').toBe('');

            // EFFECT: reloaded hero no longer renders an emblem <img>.
            await page.goto(`/spaces/${space.slug}/`);
            await expect(page.locator(heroEmblemImg)).toHaveCount(0, { timeout: 10_000 });
        } finally {
            await deleteSpaceApi(page, space.id);
        }
    });
});
