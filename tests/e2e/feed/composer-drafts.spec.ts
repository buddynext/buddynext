import { test, expect } from '../_fixtures/auth.fixture';
import { urls } from '../_fixtures/selectors';

/**
 * Wave-2 B: composer drafts persist across page reloads via localStorage.
 */
test.describe('feed / composer drafts', () => {

    test('typing in the composer survives a page refresh', async ({ authenticatedPage: page }) => {
        await page.goto(urls.feed);

        const stamp   = Date.now().toString().slice(-6);
        const content = `draft ${stamp}`;

        const textarea = page.locator('.bn-composer__prompt').first();
        await expect(textarea).toBeVisible();
        await textarea.click();
        await textarea.fill(content);

        // Wait past the 1500ms debounce so localStorage settles.
        await page.waitForTimeout(1800);

        // 'Draft saved' status briefly visible.
        const status = page.locator('.bn-composer__draft-status').first();
        await expect(status).toBeVisible();

        // Hard refresh; the prompt should re-hydrate from localStorage.
        await page.reload();

        const restored = page.locator('.bn-composer__prompt').first();
        await expect(restored).toBeVisible();
        await expect(restored).toHaveValue(content);
    });

    test('discard draft link clears the prompt + localStorage', async ({ authenticatedPage: page }) => {
        await page.goto(urls.feed);

        const stamp    = Date.now().toString().slice(-6);
        const content  = `discard ${stamp}`;

        const textarea = page.locator('.bn-composer__prompt').first();
        await textarea.click();
        await textarea.fill(content);
        await page.waitForTimeout(1800);

        const discard = page.locator('.bn-composer__draft-discard').first();
        await expect(discard).toBeVisible();
        await discard.click();

        await expect(textarea).toHaveValue('');

        await page.reload();
        const reloaded = page.locator('.bn-composer__prompt').first();
        await expect(reloaded).toHaveValue('');
    });
});
