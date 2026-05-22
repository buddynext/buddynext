import { test, expect } from '@playwright/test';
import { sel, urls } from '../_fixtures/selectors';

/**
 * J-07-login + J-08-login-with-2fa + J-09-password-reset.
 */
test.describe('auth / login', () => {
    test('redirects guest from /activity/ to auth', async ({ page }) => {
        await page.context().clearCookies();
        await page.goto(urls.feed, { waitUntil: 'domcontentloaded' });
        const url = page.url();
        expect(/auth|wp-login\.php/.test(url)).toBeTruthy();
    });

    test('login form accepts valid credentials and sets cookie', async ({ page }) => {
        const user = process.env.BN_TEST_USER ?? 'varundubey';
        const pass = process.env.BN_TEST_PASS ?? 'password';

        await page.goto('/wp-login.php');
        await page.fill(sel.loginUser, user);
        await page.fill(sel.loginPass, pass);
        await Promise.all([
            page.waitForLoadState('domcontentloaded'),
            page.click(sel.loginSubmit),
        ]);

        const cookies = await page.context().cookies();
        expect(cookies.some((c) => c.name.startsWith('wordpress_logged_in'))).toBeTruthy();
    });

    test('lost-password form renders', async ({ page }) => {
        await page.goto(urls.lostPassword);
        await expect(page.locator(sel.lostPasswordForm).first()).toBeVisible();
    });

    test.fixme(
        process.env.BN_PRO !== '1',
        'J-08-login-with-2fa  -  2FA is a Pro-only feature; set BN_PRO=1 to unmask.',
    );
    test('login prompts for TOTP when 2FA enabled (Pro)', async ({ page }) => {
        // Active only when BN_PRO=1.
        await page.goto('/wp-login.php');
        await page.fill(sel.loginUser, process.env.BN_TEST_USER_2FA ?? 'varundubey_2fa');
        await page.fill(sel.loginPass, process.env.BN_TEST_PASS_2FA ?? 'password');
        await Promise.all([page.waitForLoadState('domcontentloaded'), page.click(sel.loginSubmit)]);
        await expect(page.locator('[name="bn_totp"], #bn_totp, [data-2fa]')).toBeVisible();
    });
});
