import { test, expect } from '@playwright/test';
import { sel, urls } from '../_fixtures/selectors';
import { seedLoginUser, LOGIN_PASSWORD, dbSeedingAvailable } from '../_fixtures/db.fixture';

/**
 * J-07-login + J-08-login-with-2fa + J-09-password-reset.
 */
test.describe('auth / login', () => {
    test('guest is kept off the authenticated feed', async ({ page }) => {
        await page.context().clearCookies();
        await page.goto(urls.feed, { waitUntil: 'domcontentloaded' });
        const url = page.url();
        // A logged-out visitor never lands on the bare personal /activity/ feed.
        // Both correct gated outcomes count: the auth hub (/login/ - BuddyNext's
        // auth slug, or wp-login.php) on a private community, or the public
        // /activity/explore/ on a public one. The old assertion hardcoded `/auth/`,
        // a slug BuddyNext never uses, so it failed on every configuration.
        expect(/\/login\/|wp-login\.php|\/explore\//.test(url)).toBeTruthy();
    });

    test('login form accepts valid credentials and sets cookie', async ({ page }) => {
        // Seed a dedicated verified member with known credentials when WP-CLI is
        // available, so the real wp-login flow is deterministic on any site. Fall
        // back to the canonical env user where seeding is off (CI supplies both).
        let user = process.env.BN_TEST_USER ?? 'varundubey';
        let pass = process.env.BN_TEST_PASS ?? 'password';
        if (dbSeedingAvailable() && !process.env.BN_TEST_USER) {
            user = await seedLoginUser();
            pass = LOGIN_PASSWORD;
        }

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

    test('login prompts for TOTP when 2FA enabled (Pro)', async ({ page }) => {
        // Pro gate INSIDE the test body so it masks only this test. As a bare
        // `test.fixme(cond, ...)` at describe scope it silently skipped the three
        // free tests above (guest redirect, login+cookie, lost-password) on every
        // non-Pro build - core auth paths that must always run. (Same trap the
        // edit.spec.ts J-36 note documents.)
        test.fixme(
            process.env.BN_PRO !== '1',
            'J-08-login-with-2fa  -  2FA is a Pro-only feature; set BN_PRO=1 to unmask.',
        );
        await page.goto('/wp-login.php');
        await page.fill(sel.loginUser, process.env.BN_TEST_USER_2FA ?? 'varundubey_2fa');
        await page.fill(sel.loginPass, process.env.BN_TEST_PASS_2FA ?? 'password');
        await Promise.all([page.waitForLoadState('domcontentloaded'), page.click(sel.loginSubmit)]);
        await expect(page.locator('[name="bn_totp"], #bn_totp, [data-2fa]')).toBeVisible();
    });
});
