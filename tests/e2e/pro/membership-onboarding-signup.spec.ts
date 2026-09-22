import { test, expect } from '@playwright/test';
import { wp } from '../_fixtures/wp';
import { setRegistrationMode, dbSeedingAvailable } from '../_fixtures/db.fixture';
import { urls } from '../_fixtures/selectors';

/**
 * J-906 sell a plan during onboarding (signup).
 *
 * Covers: cap-sell-a-plan-during-onboarding
 * Roles: admin, member
 *
 * CAPABILITIES.md (buddynext-pro): "Sell a plan during onboarding? YES —
 * `Membership\OnboardingPlanStep`, gated on `buddynext_pro_page_membership`."
 * The end-to-end promise (per `SignupPlanFlow`'s own class docblock) is a
 * logged-out visitor picking a paid plan, registering, and landing in
 * checkout for exactly that plan - not a bare signup form and not a second
 * trip through the pricing page. Driven through the REAL doors:
 *
 *   1. Anonymous visitor submits a plan's buy FORM on the pricing page.
 *   2. `admin_post_nopriv_bn_membership_checkout` -> `handle_nopriv_checkout()`
 *      redirects to a server-signed `PlanIntent::signup_url()` - the token
 *      cannot be forged, so this is exercised by clicking through, not by
 *      constructing the URL.
 *   3. The signup form (`SignupPlanFlow::render_signup_plan_field()`) shows
 *      the "Joining <plan> at <price>" summary.
 *   4. Registering resumes checkout (`filter_post_register_redirect()` ->
 *      the resume ticket -> `CheckoutController::run_checkout()`).
 *
 * SOFT-SKIP when no money gateway is configured, matching
 * pro/stripe-checkout.spec.ts's own pattern - the pricing page renders a
 * disabled "Checkout is unavailable" button in that case
 * (`templates/membership/pricing.php`), which this reads directly rather than
 * following a dead end.
 *
 * ADMIN: the plan an anonymous visitor can pick is the plan the owner set up
 * and published as active/purchasable on the Tiers screen - the same seam
 * `membership-plan-change.spec.ts` and `membership-profile-field-groups.spec.ts`
 * drive via the tier editor. Verified here read-only (the listing shows it
 * live), since creating it is exercised as a write action there and repeating
 * the write path would prove nothing new about THIS promise.
 */
test.describe('pro / sell a plan during onboarding', () => {
    test.fixme(process.env.BN_PRO !== '1', 'Signup-time plan selection only exists when Pro is active.');

    const SLUG = 'bn-e2e-onboard-plan';
    let tierId = 0;
    let tierName = '';
    let pricingUrl = '';
    let bnPrevRegistration: 'open' | 'invite' | 'closed' = 'open';

    test.beforeAll(async () => {
        const stamp = Date.now().toString().slice(-6);
        tierName = `E2E Onboard Plan ${stamp}`;

        tierId = Number(
            await wp([
                'eval',
                `$svc = new \\BuddyNextPro\\Membership\\MembershipTierService();` +
                    ` $existing = $svc->get_tier_by_slug( '${SLUG}' ); if ( $existing ) { $svc->delete_tier( (int) $existing['id'] ); }` +
                    ` echo (int) $svc->create_tier( '${SLUG}', '${tierName}', '', 0, array(` +
                    `   'status' => 'active', 'price' => 11.0, 'billing_type' => 'recurring', 'billing_interval' => 'month'` +
                    ` ) );`,
            ])
        );
        expect(tierId, 'the E2E onboarding test tier should be created').toBeGreaterThan(0);

        pricingUrl = (
            await wp(['eval', `echo \\BuddyNextPro\\Membership\\MembershipPages::pricing_url();`])
        ).trim();

        if (dbSeedingAvailable()) {
            bnPrevRegistration = (await setRegistrationMode('open')) as 'open' | 'invite' | 'closed';
        }
    });

    test.afterAll(async () => {
        if (dbSeedingAvailable()) {
            await setRegistrationMode(bnPrevRegistration);
        }
        await wp([
            'eval',
            `global $wpdb; $wpdb->delete( $wpdb->prefix . 'bn_subscriptions', array( 'tier_id' => ${tierId} ) );` +
                ` ( new \\BuddyNextPro\\Membership\\MembershipTierService() )->delete_tier( ${tierId} );`,
        ]).catch(() => undefined);
    });

    test('admin: the plan is live and purchasable on the Tiers screen', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto('/?autologin=1', { waitUntil: 'domcontentloaded' });
        await page.goto('/wp-admin/admin.php?page=buddynext-monetization&tab=tiers', {
            waitUntil: 'domcontentloaded',
        });

        const row = page.locator('tr', { hasText: tierName }).or(page.locator('.bnpro-tier-card', { hasText: tierName }));
        await expect(row.first(), 'the plan created for signup should be listed').toBeVisible({ timeout: 10_000 });
        await expect(row.first()).toContainText(/active/i);
    });

    test('anonymous visitor picks the plan, is carried into signup, and registration resumes checkout', async ({
        page,
        context,
    }, testInfo) => {
        // No login for this context - the whole point is the LOGGED-OUT path.
        await context.clearCookies();
        await page.goto(pricingUrl, { waitUntil: 'domcontentloaded' });

        const card = page.locator(`#bnpro-plan-${tierId}`);
        await expect(card, 'the plan card should render for an anonymous visitor').toBeVisible({ timeout: 10_000 });

        const buyButton = card
            .locator('form.bn-membership-pricing__form:not(.bn-membership-pricing__form--points) button[type="submit"]')
            .first();

        if (!(await buyButton.isVisible().catch(() => false)) || (await buyButton.isDisabled().catch(() => true))) {
            testInfo.skip(
                true,
                'No payment gateway is configured on this harness ("Checkout is unavailable" state) - the buy form cannot be submitted, so the signup handoff cannot be exercised.'
            );
            return;
        }

        await Promise.all([page.waitForURL((u) => u.pathname.includes('/login/signup/')), buyButton.click()]);

        expect(page.url(), 'the checkout POST should carry a signed plan intent into signup').toContain('bn_plan=');

        const planNotice = page.locator('.bn-auth-notice--plan');
        await expect(planNotice, 'the signup form should summarise the chosen plan').toBeVisible();
        await expect(planNotice).toContainText(tierName);

        // Register through the real form (mirrors auth/signup.spec.ts's pattern).
        const stamp = Date.now().toString().slice(-8);
        const login = `bn_e2e_onboard_${stamp}`;
        const email = `${login}@e2e.test`;

        const userField = page.locator('#bn-signup-username').first();
        const emailField = page.locator('#bn-signup-email, #user_email, [name="user_email"]').first();
        const passField = page.locator('#bn-signup-password').first();

        if (!(await userField.isVisible().catch(() => false))) {
            testInfo.skip(true, 'Signup form did not render (registration closed on this site).');
            return;
        }

        await userField.fill(login);
        await emailField.fill(email);
        await passField.fill('Playwright!Pass1');

        const terms = page.locator('input[type="checkbox"]').first();
        if (await terms.isVisible().catch(() => false)) {
            await terms.check().catch(() => undefined);
        }

        const submit = page.locator('.bn-auth-form button[type="submit"], #wp-submit, .bn-auth__submit').first();

        // EFFECT: registering with a paid plan intent must not just create an
        // account - it must resume checkout for that plan (never leave the new
        // member stranded on a bare "welcome" screen having paid nothing and been
        // told nothing).
        const [request] = await Promise.all([
            page.waitForRequest(
                (req) =>
                    req.url().includes('checkout.stripe.com') ||
                    req.url().includes('bn_membership_resume') ||
                    req.url().includes('bn_membership=thankyou'),
                { timeout: 15_000 }
            ),
            submit.click(),
        ]);
        expect(request.url()).toMatch(/checkout\.stripe\.com|bn_membership_resume|bn_membership=thankyou/);

        // Cleanup: the account this test created.
        await wp([
            'eval',
            `require_once ABSPATH . 'wp-admin/includes/user.php';` +
                ` $u = get_user_by( 'login', '${login}' ); if ( $u ) { wp_delete_user( (int) $u->ID ); }`,
        ]).catch(() => undefined);
    });
});
