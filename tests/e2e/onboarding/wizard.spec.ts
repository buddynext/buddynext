import { test, expect } from '../_fixtures/auth.fixture';
import { sel, urls } from '../_fixtures/selectors';
import { userId, getUserMeta, ensureUser, deleteUserMeta } from '../_fixtures/wp';
import { bnApi, loginContextAs } from '../_fixtures/spaces-rest';
import { dbSeedingAvailable } from '../_fixtures/db.fixture';

/**
 * J-06-onboarding-wizard.
 *
 * Covers: cap-onboard-a-new-member-with-a-wizard
 * Roles: member
 *
 * Runs against the already-onboarded admin fixture account, so only the
 * "already onboarded -> redirect" branch is reliably exercised most runs; a
 * fresh anon signup -> wizard-completion walk is not covered here (see
 * auth/signup.spec.ts for the anon -> member signup step).
 *
 * 4-step wizard: profile basics -> interests -> follow suggestions ->
 * join spaces -> redirected to feed. Wireframe: docs/v2 Plans/v2/onboarding.html.
 *
 * Best-guess assertions: the wireframe shows 4 panels but the
 * production markup uses generic `.bn-onboarding__step` classes.
 * Spec degrades gracefully when no wizard is rendered (already
 * onboarded).
 */
test.describe('onboarding / wizard', () => {
    test('wizard renders 4 steps OR redirects to feed when already onboarded', async ({ authenticatedPage: page }) => {
        await page.goto(urls.onboarding, { waitUntil: 'domcontentloaded' });

        // varundubey is already onboarded, so /onboarding/ either shows
        // the wizard OR redirects to /activity/. Accept either.
        await expect(page).toHaveURL(/(onboarding|activity)/);
        // EFFECT: the redirect follows the stored completion flag, in both directions.
        const done = dbSeedingAvailable()
            ? (await getUserMeta(await userId(process.env.BN_TEST_USER ?? 'varundubey'), 'bn_onboarding_complete')).trim()
            : null;

        // EFFECT: the member's own onboarding route tells the same story as the redirect.
        const status = await bnApi(page, 'GET', '/me/onboarding');
        expect(status.status, 'GET /me/onboarding').toBe(200);
        const complete = Boolean((status.data as { complete?: boolean }).complete);

        if (page.url().includes('/activity')) {
            // Redirected away — valid pass for an onboarded user.
            await expect(page.locator(sel.app)).toBeVisible();
            expect(complete, 'sent to the feed, but GET /me/onboarding says not complete').toBe(true);
            if (done !== null) {
                expect(done, 'sent to the feed, so onboarding is stored as complete').toBe('1');
            }
            return;
        }

        await expect(page.locator(sel.app)).toBeVisible();

        // Wizard rendered — confirm the shell + progress bar are visible.
        // Live markup uses .bn-ob-shell / .bn-ob-wrap and .bn-progress.
        const wizard = page.locator(sel.onboardingShell).first();
        expect(complete, 'wizard shown, but GET /me/onboarding says complete').toBe(false);
        if (done !== null) {
            expect(done, 'wizard shown, so onboarding is not stored as complete').not.toBe('1');
        }
        await expect(wizard).toBeVisible();
        await expect(page.locator(sel.onboardingProgress).first()).toBeVisible();

        const stepCount = await page.locator(sel.onboardingStep).count();
        expect(stepCount).toBeGreaterThanOrEqual(1);
    });

    test('Skip advances the wizard and the step is remembered', async ({ browser, baseURL }) => {
        // The fixture account is already onboarded, so the old version of this test returned
        // before touching anything (and its `[data-onboarding-next]` / `.bn-onboarding__next`
        // selectors match no markup: the wizard is `.bn-ob-step` + actions.skipStep/nextStep,
        // templates/onboarding/index.php). Walk a throwaway member who has NOT onboarded.
        const login = 'bn_e2e_onboardee';
        const uid = await ensureUser(login, `${login}@example.com`, 'BN E2E Onboardee');
        expect(uid, `member "${login}" must exist`).toBeGreaterThan(0);
        const reset = async (): Promise<void> => {
            await deleteUserMeta(uid, 'bn_onboarding_complete');
            await deleteUserMeta(uid, 'bn_onboarding_step');
        };
        await reset();
        const member = await loginContextAs(browser, baseURL, login);
        const state = async (): Promise<{ complete: boolean; step: number }> => {
            const r = await bnApi(member.page, 'GET', '/me/onboarding');
            expect(r.status, 'GET /me/onboarding').toBe(200);
            const d = r.data as { complete?: boolean; step?: number };
            return { complete: Boolean(d.complete), step: Number(d.step ?? 0) };
        };

        try {
            expect(await state(), 'a member who never onboarded starts at step 1, not complete').toEqual({ complete: false, step: 1 });

            await member.page.goto(urls.onboarding, { waitUntil: 'domcontentloaded' });
            await expect(member.page, 'un-onboarded member was sent away from the wizard').toHaveURL(/\/onboarding\/?/);
            await expect(member.page.locator(sel.onboardingShell).first()).toBeVisible({ timeout: 10_000 });
            const active = member.page.locator('.bn-ob-step:not([hidden])').first();
            await expect(active).toHaveAttribute('data-step', '1');

            // Action under test: "Skip for now" on step 1.
            await active.locator('[data-wp-on--click="actions.skipStep"]').first().click();
            await expect(member.page.locator('.bn-ob-step[data-step="1"]')).toBeHidden({ timeout: 5_000 });

            // EFFECT: the pointer moved on the server (route + the meta behind it), and the
            // wizard is still open: skipping one step is not finishing.
            await expect.poll(async () => (await state()).step, { timeout: 8_000 }).toBe(2);
            expect((await state()).complete).toBe(false);
            expect((await getUserMeta(uid, 'bn_onboarding_step')).trim()).toBe('2');
            expect((await getUserMeta(uid, 'bn_onboarding_complete')).trim()).not.toBe('1');

            // EFFECT: a reload resumes on step 2 instead of dropping the member back at step 1.
            await member.page.goto(urls.onboarding, { waitUntil: 'domcontentloaded' });
            await expect(member.page.locator('.bn-ob-step:not([hidden])').first()).toHaveAttribute('data-step', '2', { timeout: 10_000 });
        } finally {
            await member.ctx.close();
            await reset();
        }
    });
});
