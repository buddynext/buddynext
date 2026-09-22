import { test, expect } from '@playwright/test';
import { sel, urls } from '../_fixtures/selectors';
import { setRegistrationMode, dbSeedingAvailable } from '../_fixtures/db.fixture';

/**
 * J-04-signup.
 *
 * Covers: cap-register-and-log-in-without-wp-login
 * Roles: anon, member
 *
 * Submits a fresh registration. We don't actually verify the user — that
 * lands in the dedicated verify spec. Here we only assert the form
 * accepts the submit and lands on either a verify notice or the
 * onboarding wizard.
 *
 * The signup surface is at /login/signup/ (PageRouter registers `bn_auth_action`
 * with signup as a sub-route of the auth hub; see register_auth_rules()). The
 * page can render empty if registration is closed — in that case the spec marks
 * the journey fixme rather than asserting against a non-existent form.
 */
test.describe('auth / signup', () => {
    // Open self-registration for the run so the signup form actually renders, then
    // restore whatever the site had. Without this the specs skip on any site with
    // users_can_register=0 and the submit path is never exercised. Only when WP-CLI
    // seeding is available (CI, or BN_WP_PATH locally); otherwise the in-test
    // form-visibility guard still keeps the run honest.
    let bnPrevRegistration: 'open' | 'invite' | 'closed' = 'open';
    test.beforeAll(async () => {
        if (dbSeedingAvailable()) {
            bnPrevRegistration = (await setRegistrationMode('open')) as 'open' | 'invite' | 'closed';
        }
    });
    test.afterAll(async () => {
        if (dbSeedingAvailable()) {
            await setRegistrationMode(bnPrevRegistration);
        }
    });

    test('shows registration form on /login/signup/', async ({ page }) => {
        await page.goto(urls.signup, { waitUntil: 'domcontentloaded' });

        // Gate strictly on the SIGNUP form. Falling back to the login field
        // would let this pass when registration is closed (the shared auth hub
        // shows the login form at /login/signup/), only to fail on the missing
        // #bn-signup-email assertion below. Absent signup form => fixme.
        const usernameInput = page.locator(sel.signupUser).or(page.locator(sel.signupEmail));
        const formVisible = await usernameInput.first().isVisible().catch(() => false);

        if (!formVisible) {
            test.fixme(
                true,
                'Registration closed on this site (users_can_register=0) — /signup/ renders without the form. Enable open registration to exercise this surface.'
            );
            return;
        }

        // Email + password should also be present when the form renders.
        await expect(page.locator(sel.signupEmail).first()).toBeVisible();
        await expect(page.locator(sel.signupPass).first()).toBeVisible();
    });

    test('submitting registration form lands on verify-or-onboarding state', async ({ page }) => {
        await page.goto(urls.signup, { waitUntil: 'domcontentloaded' });

        // Gate strictly on the SIGNUP form. Falling back to the login field
        // would let this pass when registration is closed (the shared auth hub
        // shows the login form at /login/signup/), only to fail on the missing
        // #bn-signup-email assertion below. Absent signup form => fixme.
        const usernameInput = page.locator(sel.signupUser).or(page.locator(sel.signupEmail));
        const formVisible = await usernameInput.first().isVisible().catch(() => false);

        if (!formVisible) {
            test.fixme(
                true,
                'Registration closed on this site (users_can_register=0). Submit flow not exercisable until enabled.'
            );
            return;
        }

        // Generate a unique-enough handle for this run.
        const stamp = Date.now().toString().slice(-8);
        const login = `e2e_${stamp}`;
        const email = `${login}@e2e.test`;

        const userField = page.locator(sel.signupUser).first();
        const emailField = page.locator(sel.signupEmail).first();
        const passField = page.locator(sel.signupPass).first();

        if (await userField.isVisible().catch(() => false)) {
            await userField.fill(login);
        }
        if (await emailField.isVisible().catch(() => false)) {
            await emailField.fill(email);
        }
        if (await passField.isVisible().catch(() => false)) {
            await passField.fill('Playwright!Pass1');
        }

        // Tick a Terms checkbox if present.
        const terms = page.locator('input[type="checkbox"]').first();
        if (await terms.isVisible().catch(() => false)) {
            await terms.check().catch(() => undefined);
        }

        const submit = page.locator(sel.loginSubmit).first();
        if (await submit.isVisible().catch(() => false)) {
            await Promise.all([
                page.waitForLoadState('domcontentloaded'),
                submit.click(),
            ]);
        }

        // Assert a real state change proving the form submitted: either we
        // landed on onboarding (account created + signed in) or a notice is
        // visible (a "check your email" pending message, or an error banner
        // explaining why the email/challenge was rejected). Merely still being
        // on an auth URL is NOT proof - the canonical signup route is
        // /login/signup/, so a `url.includes('/login')` check would be true
        // before any submit and make this test a tautology.
        const url = page.url();
        const onOnboarding = url.includes('/onboarding');
        const hasNotice = await page
            .locator('.bn-auth-field__msg, .bn-auth__notice, .message, .login .message')
            .count();
        expect(onOnboarding || hasNotice > 0).toBeTruthy();
    });
});
