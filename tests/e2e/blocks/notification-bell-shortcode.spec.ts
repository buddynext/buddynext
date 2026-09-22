import { test, expect } from '../_fixtures/auth.fixture';
import { createPage, deletePage } from '../_fixtures/wp';

/**
 * J-804 — the notification bell renders cleanly via the [buddynext_user_menu]
 * shortcode (outside a block render).
 *
 * Covers: cap-get-a-header-user-menu-and-notification-bell-in-any-theme
 * [buddynext_user_menu] shortcode; only Pro's mobile-push row exists, a
 * different feature. Flagging as friction.
 * Roles: member, admin
 *
 * The admin leg (below) authors the buddynext/notification-bell BLOCK itself
 * (not the shortcode) into a page, which is the one thing the shortcode test
 * above cannot prove: templates/blocks/notification-bell.php's guard branches
 * on `WP_Block_Supports::$block_to_render` — non-null only in a REAL block
 * render. The shortcode always takes the null branch. Without this leg the
 * `get_block_wrapper_attributes()` branch has no coverage at all.
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

    /**
     * J-804 admin leg — the notification-bell BLOCK (not the shortcode),
     * authored into a page the way an admin would place it in a block-theme
     * template or page, exercising the `get_block_wrapper_attributes()`
     * branch of the same guard the test above proves the OTHER branch of.
     *
     * Content is real Gutenberg block-comment syntax
     * (`<!-- wp:buddynext/notification-bell /-->`) authored via WP-CLI —
     * same convention as blocks/community-admin-shortcode.spec.ts's
     * `Roles: admin` leg, which also uses createPage() + authenticatedPage.
     * blocks/bn-notification-bell/block.json sets `"inserter": false` (it's
     * meant for header template areas, not the general block inserter), so
     * WP-CLI content authoring is how an admin-placed instance of this block
     * actually reaches the page in this suite, exactly as a header template
     * part would carry it.
     */
    test('J-804 admin: the buddynext/notification-bell BLOCK renders with no leaked markup', async ({
        authenticatedPage: page,
    }) => {
        const rec = await createPage('<!-- wp:buddynext/notification-bell /-->', {
            title: 'E2E Notification Bell Block',
            slug: 'e2e-notification-bell-block',
        });
        try {
            await page.goto(rec.url, { waitUntil: 'domcontentloaded' });

            const bell = page.locator('.bn-block-notification-bell').first();
            await expect(bell).toBeVisible({ timeout: 10_000 });
            await expect(bell.locator('a.bn-notification-bell-link')).toHaveCount(1);

            // Same EFFECT as the shortcode leg: a broken get_block_wrapper_attributes()
            // call leaks its class/attr string into the page's visible text.
            const bodyText = await page.locator('body').innerText();
            expect(bodyText).not.toContain('bn-block-notification-bell');
            expect(bodyText).not.toContain('data-user-id=');
        } finally {
            await deletePage(rec.id);
        }
    });
});
