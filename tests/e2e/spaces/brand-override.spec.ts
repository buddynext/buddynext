import { test, expect } from '../_fixtures/auth.fixture';
import { createSpaceApi, deleteSpaceApi } from '../_fixtures/spaces-rest';

/**
 * J-53 — Pro per-space brand override (roadmap P6.2).
 *
 * FINDING (Wave-4): this feature was REMOVED from Pro before release. The Pro
 * plugin is active on this harness (buddynext-pro + jetonomy + wpmediaverse),
 * yet there is no per-space brand control to drive:
 *   - buddynext-pro/includes/Admin/WhiteLabelAdmin.php:13 — "Front-end branding
 *     (hue, font, custom CSS, per-space) is intentionally out of scope".
 *   - buddynext-pro/includes/Core/Plugin.php:727 — "There is no front-end
 *     branding: per-space ...".
 *   - readme.txt: "White-label trimmed to backend name and logo; per-space
 *     branding was removed as out of scope."
 * The planned HueOverride.php / SpaceBrandAdmin.php and the REST
 * GET/POST /spaces/{id}/brand endpoints do not exist in the shipped code.
 *
 * So there is no hue CSS var to assert even with BN_PRO=1. This spec stays
 * `fixme`-gated: when the per-space brand feature is (re)introduced, drop the
 * fixme and assert the space hero's brand hue var flips after a save. Until
 * then the guard below documents the gap instead of false-passing.
 */
test.describe('spaces / brand override (Pro P6.2 — removed)', () => {
    test.fixme(
        true,
        'Per-space brand override was removed from Pro (WhiteLabelAdmin.php:13, Plugin.php:727). No hue control ships; nothing to drive even with BN_PRO=1. Re-enable when P6.2 returns.'
    );

    test('J-53 owner can set a brand hue per space', async ({ authenticatedPage: page }) => {
        // Reference shape of the eventual assertion (kept accurate for whoever
        // re-enables this): on the space settings Brand tab, pick a hue, save,
        // and assert the space hero's brand hue CSS custom property changed.
        const stamp = Date.now().toString().slice(-8);
        const space = await createSpaceApi(page, { name: `E2E Brand ${stamp}`, type: 'open' });
        try {
            await page.goto(`/spaces/${space.slug}/settings/?bn_stab=brand`);
            const hue = page.locator('[data-field="brand-hue"], [name="space_brand_hue"]').first();
            await expect(hue).toBeVisible();
        } finally {
            await deleteSpaceApi(page, space.id);
        }
    });
});
