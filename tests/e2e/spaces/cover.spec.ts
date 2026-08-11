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
 * J-603 — Space cover upload + remove (counterpart pair).
 *
 * Member action: owner uploads a cover image (POST /spaces/{id}/cover), then
 * removes it (DELETE /spaces/{id}/cover) — the create/remove counterpart the
 * cover-remove bug class shipped without.
 *
 * EFFECT (not presence): REST GET reports cover_image_url set after upload and
 * cleared after remove, AND the server-rendered space hero paints the cover as a
 * background-image after upload and drops it after remove (asserted after reload).
 * Throwaway space, deleted in `finally`.
 */
test.describe('spaces / cover upload + remove (J-603)', () => {
    const heroCover = '.bn-sh-hero__cover';

    test('J-603 cover upload shows on the hero; remove reverts it', async ({ authenticatedPage: page }) => {
        const stamp = Date.now().toString().slice(-8);
        const space = await createSpaceApi(page, { name: `E2E Cover ${stamp}`, type: 'open' });

        try {
            // Baseline: no cover.
            let res = await getSpace(page, space.id);
            expect(res.status).toBe(200);
            expect((res.data as SpaceRow).cover_image_url ?? '').toBe('');

            // UPLOAD.
            const up = await bnUploadImage(page, `/spaces/${space.id}/cover`);
            expect(up.status, `cover upload failed: ${JSON.stringify(up.data)}`).toBe(200);

            res = await getSpace(page, space.id);
            const afterUpload = (res.data as SpaceRow).cover_image_url ?? '';
            expect(afterUpload, 'cover_image_url not set after upload').not.toBe('');

            // EFFECT on the rendered hero: the cover paints as a background-image.
            await page.goto(`/spaces/${space.slug}/`);
            await expect(page.locator(heroCover)).toHaveAttribute('style', /background-image/i, {
                timeout: 10_000,
            });

            // REMOVE (counterpart).
            const del = await bnApi(page, 'DELETE', `/spaces/${space.id}/cover`);
            expect(del.status, `cover remove failed: ${JSON.stringify(del.data)}`).toBe(200);

            res = await getSpace(page, space.id);
            expect((res.data as SpaceRow).cover_image_url ?? '').toBe('');

            // EFFECT: reloaded hero no longer carries a background-image style.
            await page.goto(`/spaces/${space.slug}/`);
            const style = (await page.locator(heroCover).first().getAttribute('style')) ?? '';
            expect(style).not.toMatch(/background-image/i);
        } finally {
            await deleteSpaceApi(page, space.id);
        }
    });
});
