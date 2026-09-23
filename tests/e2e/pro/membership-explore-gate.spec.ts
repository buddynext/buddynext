import { test, expect } from '@playwright/test';
import { wp, ensureUser } from '../_fixtures/wp';

/**
 * J-908 gate the Explore discovery feed behind a plan.
 *
 * Covers: cap-gate-the-explore-discovery-feed-behind-a-plan
 * Roles: admin, member
 *
 * CAPABILITIES.md (buddynext-pro): "Gate the Explore discovery feed behind a
 * plan? YES — 1.1.6. `social.explore` entitlement (`EntitlementRegistry`);
 * `EntitlementGates::gate_explore_view` on Free's `buddynext_can_view_explore`
 * filter hides the deck first paint for a plan that withholds it. Guests and
 * exempt roles pass through."
 *
 * `social.explore` DEFAULTS TO TRUE in the catalogue (every plan includes
 * Explore unless an owner deliberately withholds it), so the admin action
 * that actually demonstrates this cap is UNTICKING "Explore Feed" in the tier
 * editor's Entitlements grid (`#bnpro-ent-social-explore`) for a restrictive
 * plan and saving - the same screen `membership-profile-field-groups.spec.ts`
 * drives for `profile.locked_groups`, a different entitlement on it.
 *
 * MEMBER: `EntitlementGates::gate_page_routes()` runs first (`template_redirect`,
 * priority 20) and, on a site with something to sell (this fixture's tiers are
 * both paid), 302s a restricted member straight to the pricing page with
 * `?bn_locked=social.explore` before `templates/feed/explore.php` ever renders -
 * so that is the effect this test observes. The template's OWN first-paint
 * gate (`buddynext_can_view_explore` -> the "Explore is not available on your
 * plan" empty-state) is the fallback for a site with NOTHING to sell, where the
 * redirect gate has nowhere useful to send the member and deliberately no-ops
 * (`upgrade_url()` returns ''); that path is exercised by a different fixture,
 * not this one.
 */
test.describe('pro / gate the Explore feed behind a plan', () => {
    test.fixme(process.env.BN_PRO !== '1', 'The social.explore entitlement gate only exists when Pro is active.');

    const SLUG_RESTRICTED = 'bn-e2e-explore-restricted';
    const SLUG_INCLUDED = 'bn-e2e-explore-included';
    const MEMBER_RESTRICTED = 'bn_e2e_explore_restricted';
    const MEMBER_INCLUDED = 'bn_e2e_explore_included';
    const EXPLORE_URL = '/activity/explore/';

    let restrictedTierId = 0;
    let includedTierId = 0;
    let restrictedId = 0;
    let includedId = 0;

    test.beforeAll(async () => {
        restrictedTierId = Number(
            await wp([
                'eval',
                `$svc = new \\BuddyNextPro\\Membership\\MembershipTierService();` +
                    ` $existing = $svc->get_tier_by_slug( '${SLUG_RESTRICTED}' ); if ( $existing ) { $svc->delete_tier( (int) $existing['id'] ); }` +
                    ` echo (int) $svc->create_tier( '${SLUG_RESTRICTED}', 'E2E Explore Restricted', '', 0, array(` +
                    `   'status' => 'active', 'price' => 2.0, 'billing_type' => 'recurring', 'billing_interval' => 'month'` +
                    ` ) );`,
            ])
        );
        includedTierId = Number(
            await wp([
                'eval',
                `$svc = new \\BuddyNextPro\\Membership\\MembershipTierService();` +
                    ` $existing = $svc->get_tier_by_slug( '${SLUG_INCLUDED}' ); if ( $existing ) { $svc->delete_tier( (int) $existing['id'] ); }` +
                    ` echo (int) $svc->create_tier( '${SLUG_INCLUDED}', 'E2E Explore Included', '', 0, array(` +
                    `   'status' => 'active', 'price' => 2.0, 'billing_type' => 'recurring', 'billing_interval' => 'month',` +
                    `   'entitlements' => array( 'social.explore' => true )` +
                    ` ) );`,
            ])
        );
        expect(restrictedTierId, 'the restricted tier should be created').toBeGreaterThan(0);
        expect(includedTierId, 'the included tier should be created').toBeGreaterThan(0);

        restrictedId = await ensureUser(MEMBER_RESTRICTED, `${MEMBER_RESTRICTED}@example.test`, 'E2E Explore Restricted Member');
        includedId = await ensureUser(MEMBER_INCLUDED, `${MEMBER_INCLUDED}@example.test`, 'E2E Explore Included Member');

        await wp([
            'eval',
            `( new \\BuddyNext\\Onboarding\\OnboardingService() )->finish( ${restrictedId} );` +
                ` ( new \\BuddyNext\\Onboarding\\OnboardingService() )->finish( ${includedId} );` +
                ` $sub = new \\BuddyNextPro\\Membership\\SubscriptionService();` +
                ` $exp = gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );` +
                ` $sub->create_subscription( ${restrictedId}, ${restrictedTierId}, 'manual', $exp, '', 'active' );` +
                ` $sub->create_subscription( ${includedId}, ${includedTierId}, 'manual', $exp, '', 'active' );` +
                ` \\BuddyNextPro\\Membership\\MembershipCapabilities::flush();`,
        ]);
    });

    test.afterAll(async () => {
        await wp([
            'eval',
            `global $wpdb;` +
                ` $tsvc = new \\BuddyNextPro\\Membership\\MembershipTierService();` +
                ` foreach ( array( ${restrictedTierId}, ${includedTierId} ) as $tid ) {` +
                `   $wpdb->delete( $wpdb->prefix . 'bn_subscriptions', array( 'tier_id' => $tid ) );` +
                `   $tsvc->delete_tier( $tid );` +
                ` }` +
                ` require_once ABSPATH . 'wp-admin/includes/user.php';` +
                ` foreach ( array( '${MEMBER_RESTRICTED}', '${MEMBER_INCLUDED}' ) as $login ) { $u = get_user_by( 'login', $login ); if ( $u ) { wp_delete_user( (int) $u->ID ); } }`,
        ]).catch(() => undefined);
    });

    test('admin withholds Explore on a plan from the tier editor', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto('/?autologin=1', { waitUntil: 'domcontentloaded' });
        await page.goto(`/wp-admin/admin.php?page=buddynext-monetization&tab=tiers&edit=${restrictedTierId}`, {
            waitUntil: 'domcontentloaded',
        });

        const exploreToggle = page.locator('#bnpro-ent-social-explore');
        await expect(exploreToggle, 'the Explore Feed entitlement toggle should render').toBeVisible();
        await expect(exploreToggle, 'Explore is included by default').toBeChecked();

        // The checkbox has `pointer-events: none` (assets/css/bn-admin.css)
        // - this is a standard CSS toggle-switch pattern where the wrapping
        // <label> is the real click target and forwards activation to the
        // native input, exactly as a real user's click does. Playwright's
        // `.uncheck()` targets the input directly and times out because the
        // visible `.bn-toggle--inline` span always intercepts the pointer.
        await exploreToggle.locator('xpath=ancestor::label').first().click();
        await expect(exploreToggle, 'Explore should now be unchecked').not.toBeChecked();
        await page.locator('.bnpro-plan-form__save').first().click();
        await page.waitForLoadState('domcontentloaded');

        const can = await wp([
            'eval',
            `$t = ( new \\BuddyNextPro\\Membership\\MembershipTierService() )->get_tier( ${restrictedTierId} );` +
                ` echo ! empty( $t['entitlements']['social.explore'] ) ? '1' : '0';`,
        ]);
        expect(can.trim(), 'social.explore must persist as withheld on this tier').toBe('0');
    });

    for (const width of [1280, 390]) {
        test(`a restricted member is locked out of Explore, an included member is not, at ${width}px`, async ({
            page,
        }) => {
            await page.setViewportSize({ width, height: 900 });

            // Idempotent, in case this runs before or without the admin test above.
            await wp([
                'eval',
                `$svc = new \\BuddyNextPro\\Membership\\MembershipTierService();` +
                    ` $t = $svc->get_tier( ${restrictedTierId} );` +
                    ` $ent = (array) ( $t['entitlements'] ?? array() );` +
                    ` $ent['social.explore'] = false;` +
                    ` $svc->update_tier( ${restrictedTierId}, null, null, null, array( 'entitlements' => $ent ) );` +
                    ` \\BuddyNextPro\\Membership\\MembershipCapabilities::flush();`,
            ]);

            await page.goto(`/?autologin=${MEMBER_RESTRICTED}`, { waitUntil: 'domcontentloaded' });
            await page.goto(EXPLORE_URL, { waitUntil: 'domcontentloaded' });
            // Both fixture tiers are paid (site has something to sell), so
            // gate_page_routes() 302s the restricted member to the pricing
            // page with ?bn_locked=social.explore before explore.php ever
            // renders - that redirect, not an in-place locked message, is the
            // real, observable effect here.
            expect(page.url(), 'a restricted member should be redirected off Explore to the upgrade page').toContain(
                'bn_locked=social.explore'
            );
            await expect(page.locator('.bn-explore-grid')).toHaveCount(0);

            await page.goto(`/?autologin=${MEMBER_INCLUDED}`, { waitUntil: 'domcontentloaded' });
            await page.goto(EXPLORE_URL, { waitUntil: 'domcontentloaded' });
            expect(page.url(), 'an included member should stay on Explore, not be redirected').not.toContain(
                'bn_locked'
            );
            await expect(page.locator('.bn-explore-hero__title, .bn-explore-grid').first()).toBeVisible({
                timeout: 10_000,
            });
        });
    }
});
