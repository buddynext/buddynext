import { test, expect } from '@playwright/test';
import { seedVerifyToken, getUserMeta, dbSeedingAvailable, VERIFY_PASSWORD } from '../_fixtures/db.fixture';

/**
 * J-05-email-verify.
 *
 * The most costly path in the suite: if the verify link breaks, nobody can finish
 * joining. This asserts EFFECTS, not screen strings - the token is issued by the
 * plugin's own Auth\VerificationService (seedVerifyToken), and success is read as
 * the member's verified flag flipping in the database (getUserMeta), so a copy
 * change can never make it pass hollow.
 *
 * Skips only when WP-CLI seeding is unavailable (no BN_WP_PATH) - an honest
 * environment gate, not a silent test.fixme. It runs on every CI pass.
 */
const MEMBER = 'bn_e2e_verify';

test.describe('auth / verify (J-05-email-verify)', () => {
    test.skip(!dbSeedingAvailable(), 'Needs WP-CLI seeding (set BN_WP_PATH). Runs in CI.');

    test('a valid token verifies the member and lets them log in', async ({ page }) => {
        const token = await seedVerifyToken(MEMBER);
        expect(await getUserMeta(MEMBER, 'buddynext_email_verified')).toBe('');

        // A valid token flips the verified flag (the effect), not just a nice page.
        await page.goto(`/?bn_verify=${token}`);
        await expect(page).toHaveURL(/bn_verified=1/);
        expect(await getUserMeta(MEMBER, 'buddynext_email_verified')).toBe('1');

        // And the now-verified member can log in.
        await page.goto('/wp-login.php');
        await page.fill('#user_login', MEMBER);
        await page.fill('#user_pass', VERIFY_PASSWORD);
        await Promise.all([
            page.waitForURL(/wp-admin|profile|activity|onboarding/, { timeout: 15000 }),
            page.click('#wp-submit'),
        ]);
        await expect(page).not.toHaveURL(/wp-login\.php/);
    });

    test('reusing a consumed token is refused, without a 500 or a second flip', async ({ page }) => {
        const token = await seedVerifyToken(MEMBER);

        // Consume it once.
        await page.goto(`/?bn_verify=${token}`);
        await expect(page).toHaveURL(/bn_verified=1/);

        // Reuse: the used/expired path, no server error, and the flag is unchanged.
        const resp = await page.goto(`/?bn_verify=${token}`);
        expect(resp?.status() ?? 200).toBeLessThan(500);
        await expect(page).toHaveURL(/bn_verified=0/);
        expect(await getUserMeta(MEMBER, 'buddynext_email_verified')).toBe('1');
    });

    test('an invalid token is rejected and verifies nobody', async ({ page }) => {
        // A fresh, still-unverified member the garbage token must not touch.
        const other = 'bn_e2e_verify_never';
        await seedVerifyToken(other);
        expect(await getUserMeta(other, 'buddynext_email_verified')).toBe('');

        const garbage = 'deadbeef'.repeat(8); // 64 hex chars, matches no row.
        const resp = await page.goto(`/?bn_verify=${garbage}`);
        expect(resp?.status() ?? 200).toBeLessThan(500);
        await expect(page).toHaveURL(/bn_verified=0/);
        expect(await getUserMeta(other, 'buddynext_email_verified')).toBe('');
    });
});
