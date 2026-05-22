import { test, expect } from '../_fixtures/auth.fixture';
import { urls } from '../_fixtures/selectors';

/**
 * J-53 Pro space brand override (Pro P6.2).
 */
test.describe('spaces / brand override (Pro P6.2)', () => {
    test.fixme(process.env.BN_PRO !== '1', 'Pro P6.2  -  set BN_PRO=1 to run.');

    test('owner can set a brand hue per space', async ({ authenticatedPage: page }) => {
        const slug = process.env.BN_TEST_OWNED_SPACE ?? 'general';
        await page.goto(`${urls.space(slug)}?tab=settings`);
        const hue = page.locator('[data-field="brand-hue"], [name="space_brand_hue"]').first();
        await expect(hue).toBeVisible();
    });
});
