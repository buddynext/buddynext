import { test, expect } from '../_fixtures/auth.fixture';
import { loginAs } from '../_fixtures/actor';
import { urls } from '../_fixtures/selectors';

const MEMBER_LOGIN = process.env.BN_TEST_OTHER_USER ?? 'alice';

/**
 * J-76-composer-draft.
 *
 * Wave-2 B: composer drafts persist across page reloads via localStorage.
 *
 * Covers: cap-keep-a-draft-in-the-composer-so-it-isn-t-lost
 * Roles: admin, member
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

    /**
     * Member leg. The draft is a per-browser localStorage effect, but the
     * promise is written from the member's own composer, not the admin's — a
     * regression scoped to a non-admin capability path (e.g. a draft key keyed
     * off a capability check) would not show up walking only as admin.
     */
    test('member  -  a member draft survives a page refresh', async ({ page }) => {
        await loginAs(page, MEMBER_LOGIN);
        await page.goto(urls.feed);

        const stamp   = Date.now().toString().slice(-6);
        const content = `member draft ${stamp}`;

        const textarea = page.locator('.bn-composer__prompt').first();
        await expect(textarea).toBeVisible();
        await textarea.click();
        await textarea.fill(content);

        // Wait past the 1500ms debounce so localStorage settles.
        await page.waitForTimeout(1800);

        const status = page.locator('.bn-composer__draft-status').first();
        await expect(status).toBeVisible();

        // Hard refresh; the prompt should re-hydrate from localStorage.
        await page.reload();

        const restored = page.locator('.bn-composer__prompt').first();
        await expect(restored).toBeVisible();
        await expect(restored).toHaveValue(content);
    });
});
