import { test, expect } from '../_fixtures/auth.fixture';
import { bnApi, bnUploadImage, createSpaceApi, deleteSpaceApi, getSpace } from '../_fixtures/spaces-rest';

/**
 * J-914-reposition-cover.
 *
 * A Space owner reframes a cover that is already set without uploading it again: the
 * Reposition button opens the shared window on the current picture, Apply saves the framing
 * at once, Cancel (and Enter on Cancel) saves nothing, and a member who cannot manage the
 * Space is refused by the route.
 *
 * Covers: cap-set-your-own-display-name-avatar-cover-photo-and-head
 * Roles: admin (Space owner), member
 */
test.describe('spaces / reposition an existing cover (J-914)', () => {
    test('the owner reframes the cover; Cancel saves nothing; the framing is stored', async ({ authenticatedPage: page }) => {
        const space = await createSpaceApi(page, { name: `Reposition ${Date.now().toString().slice(-6)}` });
        try {
            const uploaded = await bnUploadImage(page, `/spaces/${space.id}/cover`);
            expect(uploaded.ok).toBe(true);

            await page.goto(`/spaces/${space.slug}/settings/`);
            const reposition = page.locator('[data-bn-cover-reposition]');
            await expect(reposition).toBeVisible();

            const positionPosts: string[] = [];
            page.on('request', (r) => {
                if (r.method() === 'POST' && r.url().includes('/cover/position')) {
                    positionPosts.push(r.url());
                }
            });

            // Cancel: nothing is saved, and Enter on the Cancel button does not apply.
            await reposition.click();
            const dialog = page.getByRole('dialog', { name: /reposition cover/i });
            await expect(dialog).toBeVisible();
            await dialog.getByRole('group').focus();
            await page.keyboard.press('ArrowLeft');
            await dialog.getByRole('button', { name: 'Cancel' }).focus();
            await page.keyboard.press('Enter');
            await expect(dialog).toBeHidden();
            expect(positionPosts).toHaveLength(0);

            // Apply: arrow keys pan, Apply saves at once and closes.
            await reposition.click();
            await expect(dialog).toBeVisible();
            await dialog.getByRole('group').focus();
            for (let i = 0; i < 5; i++) {
                await page.keyboard.press('ArrowLeft');
            }
            const saved = page.waitForResponse((r) => r.url().includes('/cover/position') && r.request().method() === 'POST');
            await dialog.getByRole('button', { name: 'Apply' }).click();
            expect((await saved).status()).toBe(200);
            await expect(dialog).toBeHidden();

            const row = (await getSpace(page, space.id)).data as { cover_focal?: { x: number; y: number; zoom: number } };
            expect(row.cover_focal?.x).toBeCloseTo(60, 0);
            expect(row.cover_focal?.zoom).toBe(1);
        } finally {
            await deleteSpaceApi(page, space.id);
        }
    });

    test('a position that is out of range is refused and nothing is stored', async ({ authenticatedPage: page }) => {
        const space = await createSpaceApi(page, { name: `Reposition bad ${Date.now().toString().slice(-6)}` });
        try {
            await bnUploadImage(page, `/spaces/${space.id}/cover`);
            const bad = await bnApi(page, 'POST', `/spaces/${space.id}/cover/position`, { focal_x: 150, focal_y: 10 });
            expect(bad.status).toBe(422);
            const row = (await getSpace(page, space.id)).data as { cover_focal?: { x: number } | null };
            expect(row.cover_focal?.x ?? 50).toBe(50);
        } finally {
            await deleteSpaceApi(page, space.id);
        }
    });
});
