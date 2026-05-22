import { test, expect } from '../_fixtures/auth.fixture';
import { sel, urls } from '../_fixtures/selectors';

/**
 * J-30 profile-views widget (Pro P5.3).
 */
test.describe('profile / who-viewed widget (Pro P5.3)', () => {
    test.fixme(process.env.BN_PRO !== '1', 'Who-viewed widget is a Pro feature. Set BN_PRO=1.');

    test('widget renders on own profile', async ({ authenticatedPage: page }) => {
        const user = process.env.BN_TEST_USER ?? 'varundubey';
        await page.goto(urls.member(user));
        await expect(page.locator(sel.profileViewsWidget)).toBeVisible();
    });
});
