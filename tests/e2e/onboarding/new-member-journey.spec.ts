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
    resetRegistrationRateLimit,
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
 * runs the onboarding wizard, and actually lands somewhere real (their own
 * profile by default, or the feed on a reconfigured site). This is the
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
            await resetRegistrationRateLimit();
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

    test('anon registers, verifies, completes the wizard, and lands somewhere real', async ({ page }, testInfo) => {
        // ── 1. Anon registers via the real signup form ──────────────────────
        await page.goto(urls.signup, { waitUntil: 'domcontentloaded' });

        const usernameInput = page.locator(sel.signupUser).or(page.locator(sel.signupEmail));
        const formVisible = await usernameInput.first().isVisible().catch(() => false);
        // Conditional fixme (skips only when the signup form is genuinely absent,
        // e.g. users_can_register=0), not an unconditional fixme(true) - the
        // journey-tags gate flags the latter, and it would skip the walk always.
        test.fixme(!formVisible, 'Registration closed on this site (users_can_register=0) — /login/signup/ renders without the form.');

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

        // The in-house human-check arithmetic question is ON BY DEFAULT
        // (RegistrationGuard::challenge_enabled(), templates/auth/signup.php:641)
        // and rejects the submit client-side without an answer — this spec used
        // to silently ignore the field and fail every run against a default
        // install ("Please answer the verification question."). The question is
        // rendered as words ("What is three plus seven?", 1-9 each,
        // RegistrationGuard::number_word()) and verified server-side as a bare
        // integer, so parse the words out of the label and fill the sum.
        const challengeInput = page.locator('#bn-signup-challenge');
        if (await challengeInput.first().isVisible().catch(() => false)) {
            const question = (await page.locator('label[for="bn-signup-challenge"]').first().textContent().catch(() => '')) ?? '';
            const wordValues: Record<string, number> = {
                one: 1, two: 2, three: 3, four: 4, five: 5, six: 6, seven: 7, eight: 8, nine: 9,
            };
            const sum = (question.toLowerCase().match(/\b(one|two|three|four|five|six|seven|eight|nine)\b/g) ?? [])
                .reduce((total, word) => total + (wordValues[word] ?? 0), 0);
            await challengeInput.first().fill(String(sum));
        }

        // RegistrationGuard::too_fast() scores any submit under MIN_SECONDS (2s)
        // since the form's time-trap token was issued as spam (+100, auto-blocked
        // with "Your sign-up looked automated") — a real visitor takes longer than
        // Playwright's instant fill to read+type the form, so wait it out here
        // rather than fighting the guard the way a bot would.
        await page.waitForTimeout(2_100);

        // submitSignup() (assets/js/auth/signup-store.js) preventDefaults the
        // form and POSTs /auth/register over fetch; the page only navigates
        // once that async call resolves and the store does
        // `window.location.href = ...`. No navigation is in flight at the
        // moment of the click, so `Promise.all([waitForLoadState(...), click()])`
        // resolved immediately against the CURRENT (already-loaded) page and
        // asserted before the REST round trip ever finished - the assertion
        // below always saw the pre-submit URL. Wait for the real URL change
        // instead of a load-state event that already happened.
        const submit = page.locator(sel.loginSubmit).first();
        await submit.click();
        await page
            .waitForURL((url) => !/\/signup\/?$/.test(url.pathname), { timeout: 15_000 })
            .catch(() => undefined);

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

        // Most steps offer "Skip for now" (data-wp-on--click="actions.skipStep"),
        // and skipping the LAST step finalizes onboarding and redirects to the
        // feed itself (assets/js/onboarding/store.js skipStep()). The
        // notifications step is the one exception (templates/onboarding/index.php:766-793)
        // — it has no Skip button at all, only Back and Finish/Continue, since
        // its toggles already have sensible defaults — so the walk also drives
        // Continue (actions.nextStep) when Skip is not on the page.
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
            const continueBtn = activeStep.locator('[data-wp-on--click="actions.nextStep"]');
            if (await continueBtn.first().isVisible().catch(() => false)) {
                const beforeStep = await activeStep.getAttribute('data-step');
                await continueBtn.first().click();
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

        // ── 4. Lands somewhere real ──────────────────────────────────────────
        // OnboardingController::complete() deliberately lands a first-time
        // finisher on their OWN PROFILE by default - "the thing they just built
        // in the wizard" - rather than the feed (includes/Onboarding/
        // OnboardingController.php:414-417, RedirectSettings::onboarding()
        // fallback = PageRouter::profile_url()). An owner can reconfigure the
        // destination in Settings > Registration & Login. The feed is still a
        // valid outcome (a reconfigured site, or a wizard whose last step was a
        // Skip rather than Finish - skipStep() does redirect to the feed), so
        // accept either rather than hardcoding the profile-only default.
        const finalPath = new URL(page.url()).pathname;
        const landedOnProfile = new RegExp(`/members/${login}/?$`).test(finalPath);
        const landedOnFeed = /\/activity\/?/.test(finalPath);
        expect(landedOnProfile || landedOnFeed, `unexpected post-onboarding URL: ${page.url()}`).toBeTruthy();
        await expect(page.locator(sel.app)).toBeVisible({ timeout: 10_000 });
    });
});
