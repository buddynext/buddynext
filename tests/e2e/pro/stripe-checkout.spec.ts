import { test, expect } from '../_fixtures/auth.fixture';

/**
 * J-62 Stripe checkout button (Pro).
 */
test.describe('pro / stripe checkout', () => {
    test.fixme(process.env.BN_PRO !== '1', 'Pro tier page only exists when Pro is active.');

    test('clicking Subscribe redirects to Stripe Checkout', async ({ authenticatedPage: page }) => {
        await page.goto('/pricing/');
        const cta = page.locator('a[href*="checkout"], button[data-action="checkout"]').first();
        await expect(cta).toBeVisible();

        // Capture the navigation target  -  we don't actually want Playwright
        // to follow it into Stripe's domain.
        const [request] = await Promise.all([
            page.waitForRequest((req) => req.url().includes('checkout.stripe.com') || req.url().includes('/checkout'), { timeout: 8_000 }),
            cta.click(),
        ]);
        expect(request.url()).toMatch(/checkout/);
    });
});
