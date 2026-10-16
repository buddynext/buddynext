import { test, expect } from '@playwright/test';
import { sel, urls } from '../_fixtures/selectors';
import { setRegistrationMode, dbSeedingAvailable } from '../_fixtures/db.fixture';
import { userId, wp, dbCount, tablePrefix } from '../_fixtures/wp';
import { sessionStatus } from '../_fixtures/effects';

const NUMBER_WORDS = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten',
    'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen', 'twenty'];

/** Answer RegistrationGuard's human check ("What is four plus two?"); null when unreadable. */
function solveChallenge(question: string): number | null {
    const toNum = (w: string): number => (/^\d+$/.test(w) ? Number(w) : NUMBER_WORDS.indexOf(w));
    const m = question.toLowerCase().match(/(\w+)\s+(plus|minus|times)\s+(\w+)/);
    if (!m) return null;
    const a = toNum(m[1]);
    const b = toNum(m[3]);
    if (a < 0 || b < 0) return null;
    return m[2] === 'plus' ? a + b : m[2] === 'minus' ? a - b : a * b;
}

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

        // The register form (templates/auth/signup.php): email + name + password,
        // an optional username (only when the owner sets ask_username), any custom
        // registration fields (data-bn-reg-field), the human check and the terms
        // box. The honeypot (#bn-signup-bn_website) is never touched. Scoped to the
        // signup form: the theme also renders a login modal with its own checkbox.
        const form = page.locator('form:has(#bn-signup-email)').first();
        const userField = page.locator(sel.signupUser).first();
        if (await userField.isVisible().catch(() => false)) {
            await userField.fill(login);
        }
        await page.locator(sel.signupEmail).first().fill(email);
        const nameField = form.locator('#bn-signup-name');
        if (await nameField.isVisible().catch(() => false)) {
            await nameField.fill(`E2E Signup ${stamp}`);
        }
        await page.locator(sel.signupPass).first().fill('Playwright!Pass1');
        // A member-type (or any radio) registration field: pick its first option.
        const radio = form.locator('input[type="radio"][data-bn-reg-field]').first();
        if (await radio.count()) {
            await radio.check({ force: true });
        }
        const challenge = form.locator('#bn-signup-challenge');
        if (await challenge.isVisible().catch(() => false)) {
            const question = (await form.locator('label[for="bn-signup-challenge"]').innerText()).trim();
            const answer = solveChallenge(question);
            expect(answer, `could not read the human check "${question}"`).not.toBeNull();
            await challenge.fill(String(answer));
        }
        const terms = form.locator('input[type="checkbox"][name="terms_agreed"]');
        if (await terms.isVisible().catch(() => false)) {
            await terms.check();
        }

        // A person takes more than RegistrationGuard::MIN_SECONDS (2 s) to fill the
        // form; a script does not, and the time-trap rightly scores that as a bot.
        await page.waitForTimeout(2_500);
        const registered = page.waitForResponse(
            (r) => r.url().includes('/auth/register') && r.request().method() === 'POST',
            { timeout: 15_000 }
        );
        await form.locator('button[type="submit"]').click();
        const res = await registered;
        const body = (await res.json().catch(() => null)) as { success?: boolean; pending?: boolean; message?: string } | null;
        expect(res.status(), `register was refused: ${JSON.stringify(body)}`).toBe(200);
        expect(body?.success, `register was refused: ${JSON.stringify(body)}`).not.toBe(false);

        // Screen: the member is sent on to the onboarding wizard or the verify page
        // (AuthController::post_register_redirect), or held on an approval notice.
        if (body?.pending) {
            await expect(page.locator('.bn-auth-notice--pending')).toBeVisible();
        } else {
            await page.waitForURL(/\/onboarding|\/verify|\/activity/, { timeout: 15_000 });
        }

        // EFFECT: the registration is stored: either the user row exists (the password
        // door creates it at once, AuthController::register ~1456), or the signup is
        // parked for email verification (Auth\PendingSignup: a bn_pending_signup_*
        // transient holding the profile until the link is clicked).
        if (dbSeedingAvailable()) {
            // No username field by default: the login is derived from the email, so look up by email.
            const id = (await userId(email)) || (await userId(login));
            const p = await tablePrefix();
            const parked = await dbCount(
                `SELECT COUNT(*) FROM ${p}options WHERE option_name LIKE '_transient_bn_pending_signup_%' AND option_value LIKE '%${email}%'`
            );
            try {
                expect(id > 0 || parked > 0, `registration of "${email}" left neither a user row nor a pending signup`).toBe(true);
                if (id > 0 && !body?.pending) {
                    // A created, non-held account is signed in by the same request.
                    expect(await sessionStatus(page), 'the new member holds a session').toBe(200);
                }
            } finally {
                if (id > 0) {
                    await wp(['user', 'delete', String(id), '--yes']).catch(() => undefined);
                }
                if (parked > 0) {
                    await wp([
                        'db',
                        'query',
                        `DELETE FROM ${p}options WHERE option_name LIKE '_transient%bn_pending_signup_%' AND option_value LIKE '%${email}%'`,
                    ]).catch(() => undefined);
                }
            }
        }
    });
});
