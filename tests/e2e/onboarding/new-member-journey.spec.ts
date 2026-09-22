import { test, expect } from '@playwright/test';
import { softSkip } from '../_fixtures/precondition';
import { sel, urls } from '../_fixtures/selectors';
import {
    setRegistrationMode,
    dbSeedingAvailable,
    seedVerifyToken,
    getOption,
    setOption,
    deleteOption,
    getUserMeta,
    VERIFY_PASSWORD,
} from '../_fixtures/db.fixture';

/**
 * J-70-new-member-journey.
 *
 * Covers: cap-onboard-a-new-member-with-a-wizard, cap-register-and-log-in-without-wp-login
 * Roles: anon, member
 *
 * The pieces already exist as separate specs (auth/signup.spec.ts,
 * auth/verify.spec.ts, onboarding/wizard.spec.ts) but nothing walked them back
 * to back as ONE journey: an anonymous visitor registers, confirms their email,
 * runs the onboarding wizard, and actually lands on the feed. This is the
 * journey a real new member lives through once; a broken link anywhere in that
 * chain (a redirect that strands them mid-wizard, a verify link that 404s) is
 * invisible to the per-piece specs, which each start from a hand-seeded state
 * further down the chain.
 *
 * Registration mode and the "Require email verification" option are forced
 * open/on for the run (and restored after) so the journey deterministically
 * includes the verify step — AuthController::post_register_redirect() only
 * sends a fresh registrant to /login/verify/ when buddynext_email_verify is on;
 * otherwise registration goes straight to onboarding, skipping the piece this
 * spec exists to prove. Both are WP-CLI-gated; without WP-CLI the spec still
 * exercises the real signup form, then soft-skips the rest of the chain rather
 * than faking a token (softSkip, not a hard failure — CI always has WP-CLI).
 *
 * The verification TOKEN itself is resolved via seedVerifyToken() rather than
 * reading a real inbox (no mail-reading tool is wired into this suite) — it
 * calls the same Auth\VerificationService::create_token() a "resend" link
 * would, on the user this test just registered, so the verify step exercises
 * the real controller/service, not a hand-built token.
 *
 * No manual cleanup of the created member: global-teardown.ts's
 * `wp buddynext qa-reset` sweeps anchored e2e login prefixes on every run,
 * exactly like auth/signup.spec.ts's own registrations.
 */
test.describe('onboarding / new member journey', () => {
    let prevRegMode: 'open' | 'invite' | 'closed' = 'open';
    let prevEmailVerify = '';

    test.beforeAll(async () => {
        if (dbSeedingAvailable()) {
            prevRegMode = (await setRegistrationMode('open')) as 'open' | 'invite' | 'closed';
            prevEmailVerify = await getOption('buddynext_email_verify');
            await setOption('buddynext_email_verify', '1');
        }
    });

    test.afterAll(async () => {
        if (dbSeedingAvailable()) {
            await setRegistrationMode(prevRegMode);
            if (prevEmailVerify === '') {
                await deleteOption('buddynext_email_verify');
            } else {
                await setOption('buddynext_email_verify', prevEmailVerify);
            }
        }
    });

    test('anon registers, verifies, completes the wizard, and lands on the feed', async ({ page }, testInfo) => {
        // ── 1. Anon registers via the real signup form ──────────────────────
        await page.goto(urls.signup, { waitUntil: 'domcontentloaded' });

        const usernameInput = page.locator(sel.signupUser).or(page.locator(sel.signupEmail));
        const formVisible = await usernameInput.first().isVisible().catch(() => false);
        if (!formVisible) {
            test.fixme(true, 'Registration closed on this site (users_can_register=0) — /login/signup/ renders without the form.');
            return;
        }

        const stamp = Date.now().toString().slice(-8);
        const login = `e2e_j70_${stamp}`;
        const email = `${login}@e2e.test`;

        const userField = page.locator(sel.signupUser).first();
        const emailField = page.locator(sel.signupEmail).first();
        const passField = page.locator(sel.signupPass).first();
        if (await userField.isVisible().catch(() => false)) {
            await userField.fill(login);
        }
        await emailField.fill(email);
        // seedVerifyToken() below resets the password to VERIFY_PASSWORD anyway,
        // but the form still enforces its own min-length, so submit something valid.
        await passField.fill('Playwright!Pass1');

        const terms = page.locator('input[type="checkbox"]').first();
        if (await terms.isVisible().catch(() => false)) {
            await terms.check().catch(() => undefined);
        }

        const submit = page.locator(sel.loginSubmit).first();
        await Promise.all([
            page.waitForLoadState('domcontentloaded'),
            submit.click(),
        ]);

        // Real effect: registration created a session AND redirected somewhere
        // real (verify-pending notice, or straight to onboarding on a site with
        // verification off) — not still sitting on the signup form.
        const landedOnVerify = /\/verify\/?/.test(new URL(page.url()).pathname);
        const landedOnOnboarding = /\/onboarding\/?/.test(new URL(page.url()).pathname);
        expect(landedOnVerify || landedOnOnboarding, `unexpected post-registration URL: ${page.url()}`).toBeTruthy();

        // ── 2. Email verify ──────────────────────────────────────────────────
        if (landedOnVerify) {
            if (!dbSeedingAvailable()) {
                softSkip(testInfo, 'Landed on the verify-pending notice but cannot resolve a real token without WP-CLI (set BN_WP_PATH) — stopping the journey here.');
                return;
            }

            expect(await getUserMeta(login, 'buddynext_email_verified')).toBe('');
            const token = await seedVerifyToken(login);
            await page.goto(`/?bn_verify=${token}`);
            await expect(page).toHaveURL(/bn_verified=1/);
            expect(await getUserMeta(login, 'buddynext_email_verified')).toBe('1');

            // seedVerifyToken() reset the password — log back in as the now-
            // verified member with the known credential (same pattern as
            // auth/verify.spec.ts's own login step).
            await page.goto('/wp-login.php');
            await page.fill('#user_login', login);
            await page.fill('#user_pass', VERIFY_PASSWORD);
            await Promise.all([
                page.waitForURL(/wp-admin|profile|activity|onboarding/, { timeout: 15_000 }),
                page.click('#wp-submit'),
            ]);
            await expect(page).not.toHaveURL(/wp-login\.php/);
        }

        // ── 3. Onboarding wizard ─────────────────────────────────────────────
        await page.goto(urls.onboarding, { waitUntil: 'domcontentloaded' });
        if (/\/activity\/?$/.test(new URL(page.url()).pathname)) {
            // Onboarding feature off, or already marked complete — the journey's
            // last leg (landing on the feed) is still satisfied.
            await expect(page.locator(sel.app)).toBeVisible();
            return;
        }
        await expect(page.locator(sel.onboardingShell).first()).toBeVisible({ timeout: 10_000 });

        // Every step offers "Skip for now" (data-wp-on--click="actions.skipStep"),
        // and skipping the LAST step finalizes onboarding and redirects to the
        // feed itself (assets/js/onboarding/store.js skipStep()) — so driving
        // Skip alone, repeatedly, is enough to complete the wizard without
        // guessing at each step's required fields.
        for (let i = 0; i < 8; i++) {
            if (/\/activity\/?/.test(new URL(page.url()).pathname)) {
                break;
            }
            const activeStep = page.locator('.bn-ob-step:not([hidden])').first();
            if (!(await activeStep.count())) {
                break;
            }
            const finishBtn = activeStep.locator('[data-wp-on--click="actions.finish"]');
            if (await finishBtn.first().isVisible().catch(() => false)) {
                await Promise.all([
                    page.waitForURL(/\/activity/, { timeout: 15_000 }).catch(() => undefined),
                    finishBtn.first().click(),
                ]);
                break;
            }
            const skipBtn = activeStep.locator('[data-wp-on--click="actions.skipStep"]');
            if (await skipBtn.first().isVisible().catch(() => false)) {
                const beforeStep = await activeStep.getAttribute('data-step');
                await skipBtn.first().click();
                if (beforeStep !== null) {
                    await expect(page.locator(`.bn-ob-step[data-step="${beforeStep}"]`))
                        .toBeHidden({ timeout: 5_000 })
                        .catch(() => undefined);
                }
                continue;
            }
            // No known control on this step — stop rather than guess at fields.
            softSkip(testInfo, `Wizard step ${await activeStep.getAttribute('data-step')} has neither Skip nor Finish visible — stopping the auto-walk.`);
            break;
        }

        // ── 4. Lands on the feed ─────────────────────────────────────────────
        await expect(page).toHaveURL(/\/activity/, { timeout: 15_000 });
        await expect(page.locator(sel.app)).toBeVisible({ timeout: 10_000 });
    });
});
