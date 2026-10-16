import { test, expect } from '@playwright/test';
import { sel, urls } from '../_fixtures/selectors';
import {
    seedLoginUser,
    LOGIN_PASSWORD,
    seedTwoFactorUser,
    TWO_FACTOR_PASSWORD,
    dbSeedingAvailable,
} from '../_fixtures/db.fixture';
import { sessionStatus } from '../_fixtures/effects';
import { wp } from '../_fixtures/wp';

/**
 * J-07-login + J-08-login-with-2fa + J-09-password-reset.
 *
 * Covers: cap-register-and-log-in-without-wp-login, cap-require-two-factor
 * Roles: anon, member
 *
 * Two-factor is a FREE capability (CAPABILITIES.md: "Require two-factor? YES"),
 * registered unconditionally in includes/Core/Plugin.php - there is no Pro
 * guard anywhere in includes/Auth/TwoFactor*.php or its registration. The J-08
 * case previously masked itself behind `BN_PRO=1`, which was simply wrong (the
 * comment here used to flag this as unresolved friction); removed so the test
 * runs on every build, matching what CAPABILITIES.md promises.
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
        // EFFECT: the server holds no session for this browser, not only a redirect.
        expect(await sessionStatus(page), 'a guest must be refused by /me/profile').toBeGreaterThanOrEqual(401);
    });

    test('login form accepts valid credentials and sets cookie', async ({ page }) => {
        // Seed a dedicated verified member with known credentials when WP-CLI is
        // available, so the real wp-login flow is deterministic on any site. Fall
        // back to the canonical env user where seeding is off (CI supplies both).
        // Keyed on the PASSWORD: the journey runner always exports BN_TEST_USER
        // (the autologin account) but never its password, so keying on the user
        // logged in as the site admin with a guessed password.
        let user = process.env.BN_TEST_USER ?? 'varundubey';
        let pass = process.env.BN_TEST_PASS ?? 'password';
        if (dbSeedingAvailable() && !process.env.BN_TEST_PASS) {
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
        // EFFECT: the cookie is accepted by the REST layer, i.e. a real session exists.
        expect(await sessionStatus(page), 'the new session must read its own profile').toBe(200);
    });

    test('lost-password form renders', async ({ page }) => {
        await page.goto(urls.lostPassword);
        await expect(page.locator(sel.lostPasswordForm).first()).toBeVisible();
    });

    test('requesting a password reset stores a reset key for the member', async ({ page }) => {
        test.skip(!dbSeedingAvailable(), 'J-09 needs WP-CLI (BN_WP_PATH) to seed a member and read the reset key.');
        const user = await seedLoginUser();
        const key = async () =>
            (await wp(['eval', `$u = get_user_by( 'login', '${user}' ); echo $u ? (string) $u->user_activation_key : '';`])).trim();
        await wp(['eval', `global $wpdb; $wpdb->update( $wpdb->users, array( 'user_activation_key' => '' ), array( 'user_login' => '${user}' ) ); clean_user_cache( get_user_by( 'login', '${user}' ) );`]);
        expect(await key(), 'no reset is pending before the request').toBe('');

        await page.goto(urls.lostPassword);
        const form = page.locator(sel.lostPasswordForm).first();
        await form.locator('#user_login').fill(user);
        await Promise.all([page.waitForLoadState('domcontentloaded'), form.locator('#wp-submit').click()]);

        try {
            // EFFECT: core stored a hashed reset key on the user row; the "check your
            // email" screen alone would also render for a request that stored nothing.
            expect(await key(), 'a reset key is stored for the member').not.toBe('');
        } finally {
            await wp(['eval', `global $wpdb; $wpdb->update( $wpdb->users, array( 'user_activation_key' => '' ), array( 'user_login' => '${user}' ) ); clean_user_cache( get_user_by( 'login', '${user}' ) );`]).catch(() => undefined);
        }
    });

    test('login prompts for TOTP when 2FA enabled', async ({ page }) => {
        // J-08-login-with-2fa needs a member with 2FA actually turned on
        // (TwoFactorService::is_enabled() checks bn_2fa_enabled + bn_2fa_secret
        // user meta) - no such fixture exists on a fresh site, so seed one via
        // WP-CLI the same way seedLoginUser() does above. Without WP-CLI there
        // is no way to turn 2FA on for a known account, so skip as an honest
        // environment gap rather than asserting against an unenrolled user.
        test.skip(!dbSeedingAvailable(), 'J-08-login-with-2fa needs WP-CLI (BN_WP_PATH) to seed a 2FA-enabled member.');

        const user = await seedTwoFactorUser();

        await page.goto('/wp-login.php');
        await page.fill(sel.loginUser, user);
        await page.fill(sel.loginPass, TWO_FACTOR_PASSWORD);
        await Promise.all([page.waitForLoadState('domcontentloaded'), page.click(sel.loginSubmit)]);

        // TwoFactorLoginGuard::render_form() emits #bn_2fa_code / [name="bn_2fa_code"]
        // (includes/Auth/TwoFactorLoginGuard.php:264) - not "bn_totp", which never
        // exists anywhere in the codebase.
        await expect(page.locator('#bn_2fa_code, [name="bn_2fa_code"]')).toBeVisible();

        // The real cookie core's wp_signon set must have been cleared - a 2FA
        // account is not actually signed in until the code step verifies.
        const cookies = await page.context().cookies();
        expect(cookies.some((c) => c.name.startsWith('wordpress_logged_in'))).toBeFalsy();
        // EFFECT: no session until the code is entered.
        expect(await sessionStatus(page), 'no session before the TOTP step').toBeGreaterThanOrEqual(401);
    });
});
