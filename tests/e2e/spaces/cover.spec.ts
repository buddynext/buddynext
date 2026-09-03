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
    // The cover is an <img>, not a background-image. space-hero.php says why:
    // rendering it as an element lets the owner's focal point pan and zoom it
    // (object-position + transform:scale) the way a member cover does, with the
    // gradient on .bn-sh-hero__cover left as the no-image fallback. Asserting a
    // background-image therefore failed on upload - and passed VACUOUSLY on
    // remove, because a style that never exists can never be found.
    const heroCover = '.bn-sh-hero__cover';
    const heroCoverHasImage = '.bn-sh-hero__cover--has-image';
    const heroCoverImg = '.bn-sh-hero__cover-img';

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
            await expect(page.locator(heroCoverHasImage)).toBeVisible({ timeout: 10_000 });
            await expect(page.locator(heroCoverImg)).toHaveAttribute('src', /\S/, { timeout: 10_000 });

            // REMOVE (counterpart).
            const del = await bnApi(page, 'DELETE', `/spaces/${space.id}/cover`);
            expect(del.status, `cover remove failed: ${JSON.stringify(del.data)}`).toBe(200);

            res = await getSpace(page, space.id);
            expect((res.data as SpaceRow).cover_image_url ?? '').toBe('');

            // EFFECT: the reloaded hero drops the image and falls back to the
            // gradient - the modifier class and the <img> are both gone.
            await page.goto(`/spaces/${space.slug}/`);
            await expect(page.locator(heroCover).first()).toBeVisible();
            await expect(page.locator(heroCoverHasImage)).toHaveCount(0);
            await expect(page.locator(heroCoverImg)).toHaveCount(0);
        } finally {
            await deleteSpaceApi(page, space.id);
        }
    });
});
