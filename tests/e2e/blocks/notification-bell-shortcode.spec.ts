import { test, expect } from '../_fixtures/auth.fixture';
import { createPage, deletePage } from '../_fixtures/wp';

/**
 * J-804 — the notification bell renders cleanly via the [buddynext_user_menu]
 * shortcode (outside a block render).
 *
 * The bug: the shared bell template called get_block_wrapper_attributes(), which
 * reads WP_Block_Supports::$block_to_render['attrs'] — null outside a block render
 * — raising "Trying to access array offset on null" and (with display_errors)
 * leaking `class="bn-block-notification-bell" data-user-id="1">` as visible text
 * before the bell.
 *
 * Proven by EFFECT that holds regardless of the display_errors setting: the bell is
 * a single well-formed <div class="bn-block-notification-bell"> wrapping exactly one
 * bell link, and none of that attribute markup appears as VISIBLE text on the page.
 * A malformed-tag regression leaks the class/attr string into innerText and fails.
 */
test.describe('blocks / notification-bell shortcode (J-804)', () => {
    test('J-804 [buddynext_user_menu] renders the bell with no leaked markup', async ({
        authenticatedPage: page,
    }) => {
        const rec = await createPage('[buddynext_user_menu]', {
            title: 'E2E User Menu Shortcode',
            slug: 'e2e-user-menu-shortcode',
        });
        try {
            await page.goto(rec.url, { waitUntil: 'domcontentloaded' });

            // The bell renders in the site header too; the shortcode adds another in
            // the page content. Both flow through the same (now-guarded) template, so
            // assert on ALL of them: each is a well-formed wrapper with exactly one
            // bell link inside (a broken mid-tag render loses the link or duplicates).
            const bells = page.locator('.bn-block-notification-bell');
            const bellCount = await bells.count();
            expect(bellCount).toBeGreaterThanOrEqual(1);
            for (let i = 0; i < bellCount; i++) {
                await expect(bells.nth(i).locator('a.bn-notification-bell-link')).toHaveCount(1);
            }

            // EFFECT: the wrapper markup never leaked into the page's VISIBLE text —
            // the exact symptom of get_block_wrapper_attributes() failing mid-tag and
            // spilling `class="bn-block-notification-bell" data-user-id="1">` as content.
            const bodyText = await page.locator('body').innerText();
            expect(bodyText).not.toContain('bn-block-notification-bell');
            expect(bodyText).not.toContain('data-user-id=');
        } finally {
            await deletePage(rec.id);
        }
    });
});
