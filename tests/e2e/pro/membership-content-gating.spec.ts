import { test, expect } from '@playwright/test';
import { wp, ensureUser, createPage, deletePage } from '../_fixtures/wp';

/**
 * J-904 gate WP content behind membership.
 *
 * Covers: cap-gate-content-behind-membership
 * Roles: admin, member
 *
 * CAPABILITIES.md (buddynext-pro): "Gate content behind membership? YES —
 * `[buddynext_members_only]` (`Membership\ContentProtection`)." Per that
 * class's own docblock there is deliberately NO admin metabox/post-meta
 * trigger for the_content gate - a site that wants a specific page gated
 * writes the `[buddynext_members_only plan="<slug>"]` shortcode into the page
 * itself (or hooks `buddynextpro_content_is_protected`). The shortcode IS the
 * admin-facing configuration surface for this promise; a page is created here
 * exactly the way an owner would build one, through `createPage()`
 * (WP-CLI `wp post create`, the same door the block/classic editor writes to -
 * there is no separate metabox this suite could drive instead).
 *
 * ADMIN role is proven by `ContentProtection::viewer_has_access()`'s own
 * documented admin bypass ("without this check a logged-in admin without the
 * entitlement would hit the paywall on the front end - locked out of their
 * own members-only content"): the owner previewing paid content they wrote
 * must never be blocked by their own paywall, plan or no plan. MEMBER role is
 * the gate itself: blocked without the plan, visible with it.
 */
test.describe('pro / gate content behind membership', () => {
    test.fixme(process.env.BN_PRO !== '1', 'The members-only shortcode entitlement check only exists when Pro is active.');

    const SLUG = 'bn-e2e-content-gate-plan';
    const SECRET = 'Secret content only for plan holders.';
    const MEMBER_LOCKED = 'bn_e2e_content_locked_member';
    const MEMBER_UNLOCKED = 'bn_e2e_content_unlocked_member';

    let tierId = 0;
    let lockedId = 0;
    let unlockedId = 0;
    let pageId = 0;
    let pageUrl = '';

    test.beforeAll(async () => {
        tierId = Number(
            await wp([
                'eval',
                `$svc = new \\BuddyNextPro\\Membership\\MembershipTierService();` +
                    ` $existing = $svc->get_tier_by_slug( '${SLUG}' ); if ( $existing ) { $svc->delete_tier( (int) $existing['id'] ); }` +
                    ` echo (int) $svc->create_tier( '${SLUG}', 'E2E Content Gate Plan', '', 0, array(` +
                    `   'status' => 'active', 'price' => 6.0, 'billing_type' => 'recurring', 'billing_interval' => 'month'` +
                    ` ) );`,
            ])
        );
        expect(tierId, 'the E2E content-gate test tier should be created').toBeGreaterThan(0);

        lockedId = await ensureUser(MEMBER_LOCKED, `${MEMBER_LOCKED}@example.test`, 'E2E Content Locked Member');
        unlockedId = await ensureUser(
            MEMBER_UNLOCKED,
            `${MEMBER_UNLOCKED}@example.test`,
            'E2E Content Unlocked Member'
        );

        await wp([
            'eval',
            `( new \\BuddyNext\\Onboarding\\OnboardingService() )->finish( ${lockedId} );` +
                ` ( new \\BuddyNext\\Onboarding\\OnboardingService() )->finish( ${unlockedId} );` +
                ` ( new \\BuddyNextPro\\Membership\\SubscriptionService() )->create_subscription(` +
                `   ${unlockedId}, ${tierId}, 'manual', gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ), '', 'active'` +
                ` );` +
                ` \\BuddyNextPro\\Membership\\MembershipCapabilities::flush();`,
        ]);

        const stamp = Date.now().toString().slice(-8);
        const created = await createPage(`[buddynext_members_only plan="${SLUG}"]${SECRET}[/buddynext_members_only]`, {
            title: `E2E Content Gate ${stamp}`,
            slug: `bn-e2e-content-gate-${stamp}`,
        });
        pageId = created.id;
        pageUrl = created.url;
    });

    test.afterAll(async () => {
        await deletePage(pageId);
        await wp([
            'eval',
            `global $wpdb; $wpdb->delete( $wpdb->prefix . 'bn_subscriptions', array( 'tier_id' => ${tierId} ) );` +
                ` ( new \\BuddyNextPro\\Membership\\MembershipTierService() )->delete_tier( ${tierId} );` +
                ` require_once ABSPATH . 'wp-admin/includes/user.php';` +
                ` foreach ( array( '${MEMBER_LOCKED}', '${MEMBER_UNLOCKED}' ) as $login ) { $u = get_user_by( 'login', $login ); if ( $u ) { wp_delete_user( (int) $u->ID ); } }`,
        ]).catch(() => undefined);
    });

    for (const width of [1280, 390]) {
        test(`a member without the plan is blocked, and with it sees the content, at ${width}px`, async ({ page }) => {
            await page.setViewportSize({ width, height: 900 });

            await page.goto(`/?autologin=${MEMBER_LOCKED}`, { waitUntil: 'domcontentloaded' });
            await page.goto(pageUrl, { waitUntil: 'domcontentloaded' });

            const paywall = page.locator('.bn-paywall.bn-content-locked');
            await expect(paywall, 'a member without the plan should see the locked card').toBeVisible();
            await expect(page.getByText(SECRET)).toHaveCount(0);

            const cta = paywall.locator('a.bn-paywall__cta');
            await expect(cta).toBeVisible();
            const href = (await cta.getAttribute('href')) ?? '';
            expect(href.trim(), 'the CTA must point somewhere real').not.toBe('');
            expect(href.trim()).not.toBe('#');

            await page.goto(`/?autologin=${MEMBER_UNLOCKED}`, { waitUntil: 'domcontentloaded' });
            await page.goto(pageUrl, { waitUntil: 'domcontentloaded' });

            await expect(page.getByText(SECRET), 'a member holding the named plan should see the real content').toBeVisible();
            await expect(page.locator('.bn-paywall.bn-content-locked')).toHaveCount(0);
        });
    }

    test('an admin sees the content regardless of holding the plan', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto('/?autologin=1', { waitUntil: 'domcontentloaded' });
        await page.goto(pageUrl, { waitUntil: 'domcontentloaded' });

        await expect(page.getByText(SECRET), 'the site admin must never be locked out of their own content').toBeVisible();
        await expect(page.locator('.bn-paywall.bn-content-locked')).toHaveCount(0);
    });
});
