import { test, expect } from '@playwright/test';
import { wp, ensureUser } from '../_fixtures/wp';

/**
 * J-902 admin oversight of a member's subscription.
 *
 * Covers: cap-let-a-member-manage-their-own-subscription
 * Roles: admin
 *
 * The member half of this promise ("Let a member manage their own
 * subscription? YES — `/my-membership/` page; `MembershipShortcodes::
 * handle_cancel_post()` scopes every write to `get_current_user_id()`") is
 * already proven by `pro/membership-control-contract.spec.ts` (Roles: member).
 * `check-role-coverage.py` still reports this cap's ADMIN cell as a gap: the
 * owner's own door onto the same subscription — `MemberMembershipPanel` on
 * the single-member edit screen — had no journey. That panel exists precisely
 * because, per its own docblock, "an owner could not see which plan a member
 * was on, let alone put them on one."
 *
 * This proves the OVERSIGHT half distinct from the move-between-plans half
 * (that is `membership-plan-change.spec.ts`'s admin test, on the same
 * screen): what the admin SEES about a member's subscription must be honest
 * -  "not on any plan" when true, the real plan name after an assignment, and
 * an honest empty state for billing history rather than a silently blank
 * section. Admin screens are desktop/iPad surfaces (not a 390px requirement).
 */
test.describe('pro / admin subscription overview', () => {
    test.fixme(process.env.BN_PRO !== '1', 'The membership admin panel only exists when Pro is active.');

    const SLUG = 'bn-e2e-overview-plan';
    const MEMBER = 'bn_e2e_overview_member';

    let tierId = 0;
    let tierName = '';
    let memberId = 0;

    test.beforeAll(async () => {
        const stamp = Date.now().toString().slice(-6);
        tierName = `E2E Overview Plan ${stamp}`;

        tierId = Number(
            await wp([
                'eval',
                `$svc = new \\BuddyNextPro\\Membership\\MembershipTierService();` +
                    ` $existing = $svc->get_tier_by_slug( '${SLUG}' ); if ( $existing ) { $svc->delete_tier( (int) $existing['id'] ); }` +
                    ` echo (int) $svc->create_tier( '${SLUG}', '${tierName}', '', 0, array(` +
                    `   'status' => 'active', 'price' => 12.0, 'billing_type' => 'recurring', 'billing_interval' => 'month'` +
                    ` ) );`,
            ])
        );
        expect(tierId, 'the E2E overview test tier should be created').toBeGreaterThan(0);

        memberId = await ensureUser(MEMBER, `${MEMBER}@example.test`, 'E2E Overview Member');
        await wp([
            'eval',
            `global $wpdb; $wpdb->delete( $wpdb->prefix . 'bn_subscriptions', array( 'user_id' => ${memberId} ) );` +
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

    test('admin sees the real subscription state and can assign a plan from nothing', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto('/?autologin=1', { waitUntil: 'domcontentloaded' });

        const editUrl = `/wp-admin/admin.php?page=buddynext-members&view=edit-member&user_id=${memberId}`;
        await page.goto(editUrl, { waitUntil: 'domcontentloaded' });

        const section = page.locator('.bn-member-membership-section');
        await expect(section, 'the Membership section should render').toBeVisible();

        // EFFECT: a member with no row reads "Not on any plan", not a blank field.
        await expect(section).toContainText(/not on any plan/i);

        // EFFECT: no payments recorded is an honest empty state, not silence.
        await expect(section).toContainText(/no payments recorded for this member/i);

        // Admin assigns the plan.
        await section.locator('#bn-assign-tier').selectOption(String(tierId));
        await section.locator('input[name="bn_membership[never]"]').check();
        await page.getByRole('button', { name: 'Save Profile' }).click();
        await page.waitForLoadState('domcontentloaded');

        // EFFECT: the DB actually holds the new subscription...
        const active = await wp([
            'eval',
            `$subs = ( new \\BuddyNextPro\\Membership\\SubscriptionService() )->get_active_subscriptions( ${memberId} );` +
                ` echo (int) ( $subs[0]['tier_id'] ?? 0 );`,
        ]);
        expect(Number(active)).toBe(tierId);

        // ...and the admin's own view of it, reloaded, agrees - the plan name and a
        // "never expires" reading, not the earlier empty state.
        await page.goto(editUrl, { waitUntil: 'domcontentloaded' });
        const reloaded = page.locator('.bn-member-membership-section');
        await expect(reloaded).toContainText(tierName);
        await expect(reloaded).toContainText(/never expires/i);
        await expect(reloaded).not.toContainText(/not on any plan/i);
    });
});
