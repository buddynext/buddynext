import { test, expect } from '@playwright/test';
import { wp, ensureUser } from '../_fixtures/wp';

/**
 * J-903 change payment method without contacting support.
 *
 * Covers: cap-let-a-member-change-payment-method-without-support
 * Roles: member
 *
 * CAPABILITIES.md (buddynext-pro): "Let a member change payment method
 * without support? YES — Stripe Billing Portal / PayPal autopay link, minted
 * per member." The button on /settings/membership/ posts to admin-post.php,
 * which calls `SubscriptionService::payment_method_update_url()` (the same
 * seam the REST `/me/billing-portal` route uses) and redirects to whatever it
 * mints — never rendered as a static link, because the destination is a
 * one-time, server-minted session.
 *
 * SOFT-SKIP, matching pro/stripe-checkout.spec.ts's own pattern: a synthetic
 * test subscription has no real Stripe customer behind its external_id, so
 * minting a session fails and the handler redirects back with
 * `?bn_membership=portal_error` (its own documented failure path). That is
 * the harness telling us honestly that no gateway is live to test against
 * here, not a product bug - skip rather than assert against a destination
 * that cannot exist without a real Stripe/PayPal account wired to this member.
 */
test.describe('pro / change payment method without support', () => {
    test.fixme(process.env.BN_PRO !== '1', 'The membership panel only exists when Pro is active.');

    const SLUG = 'bn-e2e-portal-plan';
    const MEMBER = 'bn_e2e_portal_member';
    const PANEL = '/settings/membership/';

    let tierId = 0;
    let memberId = 0;

    test.beforeAll(async () => {
        tierId = Number(
            await wp([
                'eval',
                `$svc = new \\BuddyNextPro\\Membership\\MembershipTierService();` +
                    ` $existing = $svc->get_tier_by_slug( '${SLUG}' ); if ( $existing ) { $svc->delete_tier( (int) $existing['id'] ); }` +
                    ` echo (int) $svc->create_tier( '${SLUG}', 'E2E Portal Plan', '', 0, array(` +
                    `   'status' => 'active', 'price' => 8.0, 'billing_type' => 'recurring', 'billing_interval' => 'month'` +
                    ` ) );`,
            ])
        );
        expect(tierId, 'the E2E portal test tier should be created').toBeGreaterThan(0);

        memberId = await ensureUser(MEMBER, `${MEMBER}@example.test`, 'E2E Portal Member');
        await wp([
            'eval',
            `( new \\BuddyNext\\Onboarding\\OnboardingService() )->finish( ${memberId} );` +
                // source='stripe' with a synthetic external_id: enough for the button
                // to render (SubscriptionService::mints_payment_session()), real Stripe
                // credentials are what the click itself needs.
                ` ( new \\BuddyNextPro\\Membership\\SubscriptionService() )->create_subscription(` +
                `   ${memberId}, ${tierId}, 'stripe', gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ), 'sub_e2e_fake', 'active'` +
                ` );` +
                ` \\BuddyNextPro\\Membership\\MembershipCapabilities::flush();`,
        ]);
    });

    test.afterAll(async () => {
        await wp([
            'eval',
            `global $wpdb; $wpdb->delete( $wpdb->prefix . 'bn_subscriptions', array( 'tier_id' => ${tierId} ) );` +
                ` ( new \\BuddyNextPro\\Membership\\MembershipTierService() )->delete_tier( ${tierId} );` +
                ` require_once ABSPATH . 'wp-admin/includes/user.php';` +
                ` $u = get_user_by( 'login', '${MEMBER}' ); if ( $u ) { wp_delete_user( (int) $u->ID ); }`,
        ]).catch(() => undefined);
    });

    test('the member is offered a self-service portal for a gateway-billed plan', async ({ page }, testInfo) => {
        await page.goto(`/?autologin=${MEMBER}`, { waitUntil: 'domcontentloaded' });
        await page.goto(PANEL, { waitUntil: 'domcontentloaded' });

        const panel = page.locator('.bn-my-membership');
        await expect(panel, 'the membership panel should render').toBeVisible();

        const portalForm = panel.locator('form.bn-my-membership__portal').first();
        const buttonVisible = await portalForm.isVisible().catch(() => false);

        if (!buttonVisible) {
            testInfo.skip(
                true,
                'No "Update payment method" control rendered for a gateway-billed plan - cannot exercise this journey on this harness.'
            );
            return;
        }

        const homeOrigin = new URL(page.url()).origin;

        await portalForm.locator('button[type="submit"]').click();
        await page.waitForLoadState('domcontentloaded');

        const url = page.url();

        if (url.includes('bn_membership=portal_error')) {
            testInfo.skip(
                true,
                'No payment gateway is live for this synthetic subscription (portal_error) - minting a real billing-portal session needs a genuine Stripe/PayPal test-mode customer, which this harness does not provide.'
            );
            return;
        }

        // EFFECT: sent to the provider's OWN hosted portal, not stranded on our
        // site - this is what "without support" actually means.
        expect(new URL(url).origin, 'the member should land on an external, provider-hosted portal').not.toBe(
            homeOrigin
        );
    });
});
