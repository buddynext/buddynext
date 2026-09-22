import { test, expect } from '@playwright/test';
import { wp, ensureUser } from '../_fixtures/wp';

/**
 * J-905 gate profile field groups behind a plan/tier.
 *
 * Covers: cap-gate-profile-field-groups-behind-a-plan-tier
 * Roles: admin, member
 *
 * CAPABILITIES.md (buddynext-pro): "Gate profile field groups behind a
 * plan/tier? YES — owner picks the included groups per tier
 * (`EntitlementRegistry` `profile.locked_groups`; tier-editor UI). A
 * restricted member sees the locked group with an Upgrade badge and zero
 * inputs; values are retained on downgrade and restored on upgrade."
 *
 * ADMIN: the tier-editor "Entitlements" section (same screen
 * `plan-spaces-gating.spec.ts` drives for the Spaces picker) renders
 * `profile.locked_groups` as one checkbox per group, INVERTED - a ticked box
 * means "included", so locking a group means UNTICKING it
 * (`MembershipAdmin::render_entitlements_section()`'s own documented
 * inversion). The group to lock is resolved at runtime from
 * `ProfileService::get_groups()` rather than hardcoded, since which non-system
 * groups exist is a per-site fact.
 *
 * MEMBER: `templates/profile/edit.php` renders a locked group as a static
 * `.bn-ep-group--locked` section carrying the "Upgrade" badge and zero
 * inputs - shown, never hidden, "so a member cannot choose to upgrade for a
 * section they never learn exists."
 */
test.describe('pro / gate profile field groups behind a plan', () => {
    test.fixme(process.env.BN_PRO !== '1', 'Tier-level profile group locking only exists when Pro is active.');

    const SLUG = 'bn-e2e-groups-plan';
    const MEMBER_RESTRICTED = 'bn_e2e_groups_restricted';
    const MEMBER_DEFAULT = 'bn_e2e_groups_default';

    let tierId = 0;
    let groupKey = '';
    let restrictedId = 0;
    let defaultId = 0;

    test.beforeAll(async () => {
        // A real, non-system, lockable group - whichever this site actually has,
        // rather than a name that may not exist on every install.
        groupKey = (
            await wp([
                'eval',
                `if ( ! class_exists( '\\BuddyNext\\Profile\\ProfileService' ) ) { echo ''; }` +
                    ` else { foreach ( ( new \\BuddyNext\\Profile\\ProfileService() )->get_groups() as $g ) {` +
                    `   if ( empty( $g['is_system'] ) && '' !== (string) ( $g['group_key'] ?? '' ) ) { echo $g['group_key']; break; }` +
                    ` } }`,
            ])
        ).trim();
        expect(groupKey, 'this site must have at least one non-system, lockable profile group').not.toBe('');

        tierId = Number(
            await wp([
                'eval',
                `$svc = new \\BuddyNextPro\\Membership\\MembershipTierService();` +
                    ` $existing = $svc->get_tier_by_slug( '${SLUG}' ); if ( $existing ) { $svc->delete_tier( (int) $existing['id'] ); }` +
                    ` echo (int) $svc->create_tier( '${SLUG}', 'E2E Groups Plan', '', 0, array(` +
                    `   'status' => 'active', 'price' => 4.0, 'billing_type' => 'recurring', 'billing_interval' => 'month'` +
                    ` ) );`,
            ])
        );
        expect(tierId, 'the E2E groups test tier should be created').toBeGreaterThan(0);

        restrictedId = await ensureUser(
            MEMBER_RESTRICTED,
            `${MEMBER_RESTRICTED}@example.test`,
            'E2E Groups Restricted Member'
        );
        defaultId = await ensureUser(MEMBER_DEFAULT, `${MEMBER_DEFAULT}@example.test`, 'E2E Groups Default Member');

        await wp([
            'eval',
            `( new \\BuddyNext\\Onboarding\\OnboardingService() )->finish( ${restrictedId} );` +
                ` ( new \\BuddyNext\\Onboarding\\OnboardingService() )->finish( ${defaultId} );` +
                ` ( new \\BuddyNextPro\\Membership\\SubscriptionService() )->create_subscription(` +
                `   ${restrictedId}, ${tierId}, 'manual', gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ), '', 'active'` +
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
                ` foreach ( array( '${MEMBER_RESTRICTED}', '${MEMBER_DEFAULT}' ) as $login ) { $u = get_user_by( 'login', $login ); if ( $u ) { wp_delete_user( (int) $u->ID ); } }`,
        ]).catch(() => undefined);
    });

    test('admin locks a profile group on a plan from the tier editor', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto('/?autologin=1', { waitUntil: 'domcontentloaded' });
        await page.goto(`/wp-admin/admin.php?page=buddynext-monetization&tab=tiers&edit=${tierId}`, {
            waitUntil: 'domcontentloaded',
        });

        const groupCheckbox = page.locator(
            `.bnpro-ent-list input[type="checkbox"][value="${groupKey}"]`
        );
        await expect(groupCheckbox, 'the group should appear, ticked (included), in the entitlements grid').toBeVisible();
        await expect(groupCheckbox).toBeChecked();

        // Untick = lock, per render_entitlements_section()'s documented inversion.
        await groupCheckbox.uncheck();
        await page.locator('.bnpro-plan-form__save').first().click();
        await page.waitForLoadState('domcontentloaded');

        const locked = await wp([
            'eval',
            `$t = ( new \\BuddyNextPro\\Membership\\MembershipTierService() )->get_tier( ${tierId} );` +
                ` $l = (array) ( $t['entitlements']['profile.locked_groups'] ?? array() );` +
                ` echo in_array( '${groupKey}', array_map( 'strval', $l ), true ) ? '1' : '0';`,
        ]);
        expect(locked.trim(), 'the group must be persisted into profile.locked_groups').toBe('1');
    });

    for (const width of [1280, 390]) {
        test(`a restricted member sees the group locked, a default-plan member does not, at ${width}px`, async ({
            page,
        }) => {
            await page.setViewportSize({ width, height: 900 });

            // Idempotent: locks the group directly if this test runs alone (the
            // admin test above is what proves the UI does this; this only
            // guarantees the precondition when the two run out of order).
            await wp([
                'eval',
                `$svc = new \\BuddyNextPro\\Membership\\MembershipTierService();` +
                    ` $t = $svc->get_tier( ${tierId} );` +
                    ` $ent = (array) ( $t['entitlements'] ?? array() );` +
                    ` $locked = array_map( 'strval', (array) ( $ent['profile.locked_groups'] ?? array() ) );` +
                    ` if ( ! in_array( '${groupKey}', $locked, true ) ) {` +
                    `   $locked[] = '${groupKey}';` +
                    `   $ent['profile.locked_groups'] = $locked;` +
                    `   $svc->update_tier( ${tierId}, null, null, null, array( 'entitlements' => $ent ) );` +
                    ` }` +
                    ` \\BuddyNextPro\\Membership\\MembershipCapabilities::flush();`,
            ]);

            await page.goto(`/?autologin=${MEMBER_RESTRICTED}`, { waitUntil: 'domcontentloaded' });
            await page.goto(`/members/${MEMBER_RESTRICTED}/edit/`, { waitUntil: 'domcontentloaded' });

            const lockedSection = page.locator('.bn-ep-group--locked');
            await expect(lockedSection, 'the restricted plan should show a locked group section').toHaveCount(1, {
                timeout: 10_000,
            });
            await expect(lockedSection.locator('.bn-ep-locked-badge')).toHaveText(/upgrade/i);
            await expect(lockedSection.locator('input, textarea, select')).toHaveCount(0);

            await page.goto(`/?autologin=${MEMBER_DEFAULT}`, { waitUntil: 'domcontentloaded' });
            await page.goto(`/members/${MEMBER_DEFAULT}/edit/`, { waitUntil: 'domcontentloaded' });

            await expect(
                page.locator('.bn-ep-group--locked'),
                'a member not on the restricted plan should see no locked groups'
            ).toHaveCount(0);
        });
    }
});
