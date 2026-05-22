import { test, expect } from '../_fixtures/auth.fixture';
import { urls } from '../_fixtures/selectors';

/**
 * J-63 AI moderation toggle (Pro).
 */
test.describe('pro / ai moderation', () => {
    test.fixme(process.env.BN_PRO !== '1', 'AI moderation lands in Pro only.');

    test('AI moderation toggle visible in settings', async ({ authenticatedPage: page }) => {
        await page.goto(urls.adminSettings);
        const toggle = page.locator('input[name="bn_ai_moderation"], [data-toggle="ai-moderation"]').first();
        await expect(toggle).toBeVisible();
    });
});
