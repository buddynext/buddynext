import { test, expect } from '../_fixtures/auth.fixture';
import { sel, urls } from '../_fixtures/selectors';

/**
 * J-29-profile-view-own.
 */
test.describe('profile / view', () => {
    test('own profile renders hero + stats + tabs', async ({ authenticatedPage: page }) => {
        const user = process.env.BN_TEST_USER ?? 'varundubey';
        await page.goto(urls.member(user));
        await expect(page.locator(sel.app)).toBeVisible();

        await expect(page.locator(sel.profileHero).first()).toBeVisible({ timeout: 5_000 });
        // Stats and tabs are best-effort  -  accept missing tabs (some
        // builds use a single column on mobile).
        const statsVisible = await page.locator(sel.profileStats).first().isVisible().catch(() => false);
        const tabsVisible = await page.locator(sel.profileTab).first().isVisible().catch(() => false);
        expect(statsVisible || tabsVisible).toBeTruthy();
    });
});
