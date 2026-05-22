import { test, expect } from '../_fixtures/auth.fixture';
import { urls } from '../_fixtures/selectors';

/**
 * J-59 custom domains (Pro P6.3).
 */
test.describe('admin / custom domains (Pro P6.3)', () => {
    test.fixme(process.env.BN_PRO !== '1', 'Pro P6.3  -  set BN_PRO=1.');

    test('domains admin page exposes add-domain form', async ({ authenticatedPage: page }) => {
        await page.goto(urls.adminCustomDomains);
        const form = page.locator('form:has(input[name="bn_domain"]), form[data-domains-form]').first();
        await expect(form).toBeVisible();
    });
});
