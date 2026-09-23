import { test, expect } from '@playwright/test';
import { test as adminTest } from '../_fixtures/auth.fixture';
import { seedVerifyToken, getUserMeta, dbSeedingAvailable, VERIFY_PASSWORD, getOption, setOption, deleteOption } from '../_fixtures/db.fixture';
import { readRestNonce, restPost } from '../_fixtures/feed-wave1.helpers';
import type { Browser, BrowserContext, APIRequestContext } from '@playwright/test';

/**
 * J-05-email-verify.
 *
 * Covers: cap-verify-email-before-posting
 * Roles: anon, member, admin
 *
 * Member is walked only in the first test (token verify -> login as the now-
 * verified user); the reuse and invalid-token cases stay anon throughout. The
 * admin leg lives in its own describe() below (it needs authenticatedPage,
 * which this file's anon-first tests deliberately don't use).
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

/**
 * Open a session for a member who has NOT completed onboarding and resolve
 * their wp_rest nonce.
 *
 * A member seeded straight through seedVerifyToken() (WP-CLI wp_create_user(),
 * bypassing the real registration pipeline) has no bn_onboarding_complete meta,
 * so PageRouter redirects /activity/ to /onboarding/ for them. This spec only
 * needs a nonce to attempt a REST write, not feed content, so it reads the
 * nonce straight off /onboarding/ (which embeds one for its own REST calls —
 * see templates/onboarding/index.php's `restNonce` context field) instead of
 * reusing feed-wave1.helpers' openMemberSession(), which asserts the OPPOSITE
 * invariant (that the member DOES reach the feed) and would throw here.
 */
async function openUnonboardedMemberSession(
    browser: Browser,
    login: string
): Promise<{ ctx: BrowserContext; request: APIRequestContext; nonce: string }> {
    const ctx = await browser.newContext();
    const p = await ctx.newPage();
    await p.goto(`/?autologin=${encodeURIComponent(login)}`, { waitUntil: 'domcontentloaded' });
    await p.goto('/onboarding/', { waitUntil: 'domcontentloaded' });
    const nonce = await readRestNonce(p);
    await p.close();
    return { ctx, request: ctx.request, nonce };
}

/**
 * J-05-email-verify — admin leg.
 *
 * Covers: cap-verify-email-before-posting
 * Roles: admin
 *
 * The member-side tests above prove the verify LINK works. This proves the
 * other half of the promise: an admin can see and turn on "Require email
 * verification" (Settings -> Registration & Login,
 * includes/Admin/Settings.php::fields_registration() /
 * render_tab_registration(), toggle id #bn-toggle-buddynext_email_verify), and
 * the effect actually holds — a member who registered AFTER that switch was
 * flipped and never verified is blocked from posting (Feed\PostService::create()'s
 * email_unverified 403).
 *
 * The member is seeded (not registered through the UI) strictly AFTER turning
 * the setting on, so VerificationService::is_verified()'s grandfather clause
 * (accounts that predate buddynext_email_verify_enabled_at are exempt) cannot
 * accidentally exempt them and hide a real regression.
 */
adminTest.describe('auth / verify — admin (J-05-email-verify)', () => {
    adminTest.skip(!dbSeedingAvailable(), 'Needs WP-CLI seeding (set BN_WP_PATH). Runs in CI.');

    adminTest(
        'admin can require email verification, and an unverified member is blocked from posting',
        async ({ authenticatedPage: page, browser }) => {
            const prevRequire = await getOption('buddynext_email_verify');

            try {
                await page.goto('/wp-admin/admin.php?page=buddynext-settings&tab=registration', { waitUntil: 'domcontentloaded' });
                const toggle = page.locator('#bn-toggle-buddynext_email_verify');
                await expect(toggle).toBeVisible({ timeout: 10_000 });

                if (!(await toggle.isChecked())) {
                    await toggle.check({ force: true });
                    const submit = page
                        .locator('form.bn-settings-form button[type="submit"], form.bn-settings-form input[type="submit"], #submit')
                        .first();
                    await Promise.all([
                        page.waitForURL(/settings-updated=true/, { timeout: 15_000 }),
                        submit.click(),
                    ]);
                }
                expect(await getOption('buddynext_email_verify')).toBe('1');

                // Effect: a freshly-seeded, never-verified member is blocked from
                // posting — over the real REST route the composer itself uses.
                const login = 'bn_e2e_verify_block';
                await seedVerifyToken(login);
                expect(await getUserMeta(login, 'buddynext_email_verified')).toBe('');

                const member = await openUnonboardedMemberSession(browser, login);
                try {
                    const created = await restPost<{ code?: string }>(member.request, member.nonce, '/posts', {
                        content: 'this should be blocked while unverified',
                        privacy: 'public',
                    });
                    expect(created.status, `unverified post -> ${created.status}`).toBe(403);
                    expect(created.body.code).toBe('email_unverified');
                } finally {
                    await member.ctx.close().catch(() => undefined);
                }
            } finally {
                if (prevRequire === '') {
                    await deleteOption('buddynext_email_verify');
                } else {
                    await setOption('buddynext_email_verify', prevRequire);
                }
            }
        }
    );
});
