import { test, expect } from '../_fixtures/auth.fixture';
import { solidPng } from '../_fixtures/solid-png';
import { wp } from '../_fixtures/wp';

/**
 * J-911-write-hub-crop-preview.
 *
 * The crop preview under Member Blog's featured-image box on the write page
 * (MemberBlogBridge::render_crop_preview, @buddynext/blog-write-crop): hidden until a
 * photo is chosen, both crops and the size guidance for a wide photo, text only for a
 * photo under the cover minimum, hidden again after Remove, and Member Blog's own
 * 1200 x 630 line out of the way so the form states one size.
 *
 * Covers: cap-write-a-long-form-article-from-the-feed
 * Roles: admin
 *
 * Needs Member Blog active; skipped without it. Images are built in the test.
 */
test.describe('write hub / crop preview (J-911)', () => {
    test('pick, small photo, remove', async ({ authenticatedPage: page }) => {
        try {
            await wp(['plugin', 'is-active', 'buddypress-member-blog']);
        } catch {
            test.skip(true, 'Member Blog is not active on this site');
        }
        page.on('dialog', (d) => d.accept().catch(() => undefined));
        await page.goto('/add-new-post/');

        const box = page.locator('.bn-crop-preview');
        // On a phone the whole Post settings sidebar starts collapsed behind a toggle.
        const sidebar = page.locator('#bpmb-composer-sidebar');
        if (await sidebar.evaluate((el) => el.classList.contains('is-collapsed'))) {
            await page.locator('.bpmb-composer-sidebar__toggle').click();
        }
        const input = page.locator('#bp_member_blog_post_featured_image');
        await expect(box).toBeHidden();

        await input.setInputFiles(solidPng(1600, 900));
        await expect(box).toBeVisible();
        await expect(box).toContainText('Wide photos work best.');
        await expect(box.locator('.bn-crop-preview__crop--feed img')).toBeVisible();
        await expect(box.locator('.bn-crop-preview__crop--list img')).toBeVisible();
        // One size rule: Member Blog's own line is not shown beside ours.
        await expect(page.locator('#featured-image-help')).toBeHidden();

        await input.setInputFiles(solidPng(400, 300));
        await expect(box).toContainText('This photo is small, so it will not be used as a cover.');
        await expect(box.locator('.bn-crop-preview__crops')).toBeHidden();

        // Remove asks the server first when the post already has an id (autosave), then hides the preview.
        // The button sits in a hover overlay that the sticky Publish bar can cover, so fire the click on it directly.
        await page.locator('#remove_feature_img').dispatchEvent('click');
        await expect(box).toBeHidden({ timeout: 15_000 });
    });
});
