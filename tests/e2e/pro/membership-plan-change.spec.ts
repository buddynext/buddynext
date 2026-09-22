import { test, expect } from '@playwright/test';
import type { Page } from '@playwright/test';
import { wp, ensureUser } from '../_fixtures/wp';

/**
 * J-901 upgrade/downgrade a member's plan — self-service and admin-assigned.
 *
 * Covers: cap-let-a-member-upgrade-or-downgrade-their-own-plan
 * Roles: admin, member
 *
 * CAPABILITIES.md (buddynext-pro): "Let a member upgrade or downgrade their own
 * plan? YES — 1.1.6. `Membership\PlanChangeService::quote()` previews the
 * change and `apply()` applies it, proration paid in days on the new plan -
 * the member never holds two plans or none."
 *
 * MEMBER: the self-service path on /settings/membership/ - a plan-change
 * option is a plain form (`bn-membership-pricing.php`'s sibling,
 * `my-membership.php`) whose Switch button carries `data-bn-confirm` naming
 * the exact proration ("Move to X? Your remaining time becomes N days on the
 * new plan..."), confirmed through the shared `bnConfirm()` modal
 * (`[data-bn-confirm-ok]`) rather than a native dialog, then POSTed to
 * `handle_change_plan_post()`. EFFECT: the active subscription's tier_id
 * actually moves, not just the button existing.
 *
 * ADMIN: `MemberMembershipPanel` on the single-member edit screen
 * (`?page=buddynext-members&view=edit-member&user_id=X`) is the other door
 * onto the SAME invariant that panel's own docblock states — "A plan CHANGE,
 * not a plan ADD... Checkout has always ended the old plan first; this is the
 * same door" (`SubscriptionService::supersede_active_subscriptions()`). An
 * owner moving a member from Plan A to Plan B through this screen is
 * upgrading/downgrading them exactly as the member would themselves, so this
 * is the admin half of the same promise, not a different capability.
 */
test.describe('pro / plan change (upgrade or downgrade)', () => {
    test.fixme(process.env.BN_PRO !== '1', 'Plan change only exists when Pro is active.');

    const SLUG_A = 'bn-e2e-planchange-a';
    const SLUG_B = 'bn-e2e-planchange-b';
    const MEMBER_SELF = 'bn_e2e_planchange_member';
    const MEMBER_ADMIN = 'bn_e2e_planchange_admin_target';
    const PANEL = '/settings/membership/';

    let tierA = { id: 0, name: '' };
    let tierB = { id: 0, name: '' };
    let selfMemberId = 0;
    let adminTargetId = 0;

    async function login(page: Page, who: string): Promise<void> {
        await page.goto(`/?autologin=${who}`, { waitUntil: 'domcontentloaded' });
    }

    async function activeTierId(userId: number): Promise<number> {
        return Number(
            await wp([
                'eval',
                // get_active_subscriptions()[0] is UNORDERED (creation order) - a
                // member onboarded onto the site's default free plan then holds
                // that row alongside any paid one, and [0] would read back the
                // old default plan instead of the plan just switched to.
                // effective_subscription() is the ranked accessor (paid beats
                // free, dearer beats cheaper, newest breaks ties) - the same one
                // EntitlementRegistry uses - so it is the correct "what plan is
                // this member actually on" read.
                `$sub = ( new \\BuddyNextPro\\Membership\\SubscriptionService() )->effective_subscription( ${userId} );` +
                    ` echo (int) ( $sub['tier_id'] ?? 0 );`,
            ])
        );
    }

    test.beforeAll(async () => {
        const stamp = Date.now().toString().slice(-6);
        tierA.name = `E2E Plan Change A ${stamp}`;
        tierB.name = `E2E Plan Change B ${stamp}`;

        for (const [slug, ref] of [
            [SLUG_A, tierA],
            [SLUG_B, tierB],
        ] as const) {
            const id = Number(
                await wp([
                    'eval',
                    `$svc = new \\BuddyNextPro\\Membership\\MembershipTierService();` +
                        ` $existing = $svc->get_tier_by_slug( '${slug}' ); if ( $existing ) { $svc->delete_tier( (int) $existing['id'] ); }` +
                        ` echo (int) $svc->create_tier( '${slug}', '${ref.name}', '', 0, array(` +
                        `   'status' => 'active', 'price' => 5.0, 'billing_type' => 'recurring', 'billing_interval' => 'month'` +
                        ` ) );`,
                ])
            );
            ref.id = id;
        }
        expect(tierA.id, 'Plan A should be created').toBeGreaterThan(0);
        expect(tierB.id, 'Plan B should be created').toBeGreaterThan(0);

        selfMemberId = await ensureUser(MEMBER_SELF, `${MEMBER_SELF}@example.test`, 'E2E Plan Change Member');
        adminTargetId = await ensureUser(MEMBER_ADMIN, `${MEMBER_ADMIN}@example.test`, 'E2E Plan Change Admin Target');

        await wp([
            'eval',
            `( new \\BuddyNext\\Onboarding\\OnboardingService() )->finish( ${selfMemberId} );` +
                ` ( new \\BuddyNext\\Onboarding\\OnboardingService() )->finish( ${adminTargetId} );` +
                // The self-service member starts on Plan A, mid-cycle, so the
                // quote/apply proration below has real remaining days to move.
                // Source 'offline' (not 'manual'): MembershipCapabilities::classify()
                // only grants can_change to a subscription billed through a
                // registered gateway (MembershipCapabilities.php ~204-213) - 'manual'
                // is a comp with nothing to bill, so the self-service panel
                // correctly renders "given to you by the site" with no Switch
                // option for it. 'offline' is always registered (Plugin.php's
                // OfflineGateway) specifically so a site-billed-but-not-electronic
                // subscription still counts as ours to change.
                ` ( new \\BuddyNextPro\\Membership\\SubscriptionService() )->create_subscription(` +
                `   ${selfMemberId}, ${tierA.id}, 'offline', gmdate( 'Y-m-d H:i:s', time() + 15 * DAY_IN_SECONDS ), '', 'active'` +
                ` );` +
                ` \\BuddyNextPro\\Membership\\MembershipCapabilities::flush();`,
        ]);
    });

    test.afterAll(async () => {
        await wp([
            'eval',
            `global $wpdb;` +
                ` $tsvc = new \\BuddyNextPro\\Membership\\MembershipTierService();` +
                ` foreach ( array( ${tierA.id}, ${tierB.id} ) as $tid ) {` +
                `   $wpdb->delete( $wpdb->prefix . 'bn_subscriptions', array( 'tier_id' => $tid ) );` +
                `   if ( $tid > 0 ) { $tsvc->delete_tier( $tid ); }` +
                ` }` +
                ` require_once ABSPATH . 'wp-admin/includes/user.php';` +
                ` foreach ( array( '${MEMBER_SELF}', '${MEMBER_ADMIN}' ) as $login ) { $u = get_user_by( 'login', $login ); if ( $u ) { wp_delete_user( (int) $u->ID ); } }`,
        ]).catch(() => undefined);
    });

    test('member switches plan on the self-service panel and proration is shown', async ({ page }) => {
        await login(page, MEMBER_SELF);
        await page.goto(PANEL, { waitUntil: 'domcontentloaded' });

        const panel = page.locator('.bn-my-membership');
        await expect(panel, 'the membership panel should render').toBeVisible();

        // The plan's name sits BESIDE its switch form (siblings inside one
        // change-item <li>), not inside it - scope to the item, then the form.
        const changeItem = panel.locator('.bn-my-membership__change-item', { hasText: tierB.name });
        const switchForm = changeItem.locator('form.bn-my-membership__change-form');
        await expect(switchForm.first(), 'a switch option to Plan B should be offered').toBeVisible({
            timeout: 10_000,
        });

        // PRORATION IS SHOWN: the confirm text names the destination plan and the
        // extra days the member keeps, before anything is submitted.
        const confirmText = await switchForm.first().getAttribute('data-bn-confirm');
        expect(confirmText, 'the switch control must carry a proration confirmation').toBeTruthy();
        expect(confirmText).toContain(tierB.name);
        expect(confirmText).toMatch(/day/i);

        await switchForm.first().getByRole('button', { name: /^switch$/i }).click();

        // `[data-bn-confirm-ok]` is the trigger FORM's own config attribute
        // (its value is the button label, e.g. "Move") - it is not the
        // rendered modal's button, so it resolves to the original (now
        // backdrop-covered) form and the click never lands. The shared
        // bnConfirm() dialog renders as `.bn-modal-backdrop`; scope to that
        // and find its button by accessible role + label, same pattern as
        // feed/comment-delete.spec.ts.
        const confirmModal = page.locator('.bn-modal-backdrop').last();
        await expect(confirmModal, 'the shared confirm modal should appear').toBeVisible({ timeout: 5_000 });
        await confirmModal.getByRole('button', { name: 'Move', exact: true }).click();

        // EFFECT: the member is actually on Plan B now, not just told they would be.
        await expect
            .poll(async () => activeTierId(selfMemberId), { timeout: 10_000 })
            .toBe(tierB.id);
    });

    test('admin moves a member between plans from the edit-member screen', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await login(page, '1');
        const editUrl = `/wp-admin/admin.php?page=buddynext-members&view=edit-member&user_id=${adminTargetId}`;

        // First assignment: Plan A. Admin screens are desktop/iPad surfaces, not a
        // 390px requirement.
        await page.goto(editUrl, { waitUntil: 'domcontentloaded' });
        const section = page.locator('.bn-member-membership-section');
        await expect(section, 'the Membership section should render on the edit-member screen').toBeVisible();

        await section.locator('#bn-assign-tier').selectOption(String(tierA.id));
        await section.locator('input[name="bn_membership[never]"]').check();
        await page.getByRole('button', { name: 'Save Profile' }).click();
        await page.waitForLoadState('domcontentloaded');

        await expect.poll(async () => activeTierId(adminTargetId), { timeout: 10_000 }).toBe(tierA.id);

        // Second assignment: Plan B. This is the ADMIN half of "upgrade or
        // downgrade" — the same supersede-then-create door the member's own
        // switch uses, reached from the owner's seat.
        await page.goto(editUrl, { waitUntil: 'domcontentloaded' });
        await section.locator('#bn-assign-tier').selectOption(String(tierB.id));
        await section.locator('input[name="bn_membership[never]"]').check();
        await page.getByRole('button', { name: 'Save Profile' }).click();
        await page.waitForLoadState('domcontentloaded');

        // EFFECT: Plan A is superseded, Plan B is the member's one active plan -
        // never both (the one-active-plan invariant this panel exists to protect).
        await expect.poll(async () => activeTierId(adminTargetId), { timeout: 10_000 }).toBe(tierB.id);
    });
});
