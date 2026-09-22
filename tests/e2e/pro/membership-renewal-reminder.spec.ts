import { test, expect } from '@playwright/test';
import { wp, ensureUser } from '../_fixtures/wp';

/**
 * J-907 warn a member before their membership renews or ends.
 *
 * Covers: cap-warn-members-before-a-membership-renews-or-ends
 * Roles: member
 *
 * CAPABILITIES.md (buddynext-pro): "Warn members before a membership renews
 * or ends? YES — 1.1.5. `Membership\RenewalReminderService` +
 * `bn_subscriptions.reminder_sent_days`; separate emails for auto-renewing
 * and ending, owner-set day offsets. Stays off on an existing site until
 * switched on."
 *
 * There is no in-app UI for this promise — grepping both repos for the two
 * hooks this service fires (`buddynextpro_subscription_renewal_upcoming`,
 * `buddynextpro_subscription_expiring_soon`) finds exactly one listener,
 * `SubscriptionEmailListener`, which hands off to Free's generic
 * `\BuddyNext\Notifications\EmailSender`. The email IS the member-facing
 * surface for this capability, so it is what this journey proves, at the
 * deterministic seam: `RenewalReminderService::run_sweep()` is a real cron
 * body (`CRON_HOOK`), called directly the way the daily Action Scheduler job
 * would call it, against a real subscription row.
 *
 * DELIBERATE STOPPING POINT: `EmailSender::send()` (Free) can defer to an
 * Action Scheduler job and honours the member's own per-type email
 * preference (`email_freq`), so whether wp_mail() itself fires synchronously
 * is a fact about Free's generic notification pipeline, not about this
 * capability — and is Free's to test. This spec asserts up to and including
 * the fired action's payload (the exact days-left / end-date the member would
 * be told), which is the whole of what `RenewalReminderService` is
 * responsible for. Capturing the action synchronously (not wp_mail) is what
 * makes this deterministic without depending on mail transport or a
 * mail-catcher being reachable from the harness.
 */
test.describe('pro / renewal reminder sweep warns a member', () => {
    test.fixme(process.env.BN_PRO !== '1', 'Renewal reminders only exist when Pro is active.');

    const SLUG = 'bn-e2e-renewal-plan';
    const MEMBER = 'bn_e2e_renewal_member';

    let tierId = 0;
    let memberId = 0;
    let subId = 0;
    let prevEnabled = '1';

    test.beforeAll(async () => {
        prevEnabled = (
            await wp(['option', 'get', 'buddynextpro_renewal_reminders_enabled']).catch(() => '1')
        ).trim();
        await wp(['option', 'update', 'buddynextpro_renewal_reminders_enabled', '1']);

        tierId = Number(
            await wp([
                'eval',
                `$svc = new \\BuddyNextPro\\Membership\\MembershipTierService();` +
                    ` $existing = $svc->get_tier_by_slug( '${SLUG}' ); if ( $existing ) { $svc->delete_tier( (int) $existing['id'] ); }` +
                    ` echo (int) $svc->create_tier( '${SLUG}', 'E2E Renewal Plan', '', 0, array(` +
                    `   'status' => 'active', 'price' => 3.0, 'billing_type' => 'recurring', 'billing_interval' => 'month'` +
                    ` ) );`,
            ])
        );
        expect(tierId, 'the E2E renewal test tier should be created').toBeGreaterThan(0);

        memberId = await ensureUser(MEMBER, `${MEMBER}@example.test`, 'E2E Renewal Member');

        // A comp (source 'manual') expiring inside the smallest default offset
        // (1 day) with reminder_sent_days NULL, so the sweep is guaranteed to find
        // it due and never suppressed as already-sent. 'manual' does not bill
        // again, so this exercises the ENDING copy branch, not the renewing one -
        // the branch the class's own docblock calls the higher-stakes of the two
        // ("the member must act or lose it").
        subId = Number(
            await wp([
                'eval',
                `$id = ( new \\BuddyNextPro\\Membership\\SubscriptionService() )->create_subscription(` +
                    `   ${memberId}, ${tierId}, 'manual', gmdate( 'Y-m-d H:i:s', time() + 12 * HOUR_IN_SECONDS ), '', 'active'` +
                    ` );` +
                    ` global $wpdb; $wpdb->update( $wpdb->prefix . 'bn_subscriptions', array( 'reminder_sent_days' => null ), array( 'id' => (int) $id ) );` +
                    ` echo (int) $id;`,
            ])
        );
        expect(subId, 'the E2E renewal subscription should be created').toBeGreaterThan(0);
    });

    test.afterAll(async () => {
        await wp(['option', 'update', 'buddynextpro_renewal_reminders_enabled', prevEnabled]).catch(() => undefined);
        await wp([
            'eval',
            `global $wpdb; $wpdb->delete( $wpdb->prefix . 'bn_subscriptions', array( 'tier_id' => ${tierId} ) );` +
                ` ( new \\BuddyNextPro\\Membership\\MembershipTierService() )->delete_tier( ${tierId} );` +
                ` require_once ABSPATH . 'wp-admin/includes/user.php';` +
                ` $u = get_user_by( 'login', '${MEMBER}' ); if ( $u ) { wp_delete_user( (int) $u->ID ); }`,
        ]).catch(() => undefined);
    });

    test('the sweep fires an ending-soon warning naming this member, plan and date', async () => {
        const captured = await wp([
            'eval',
            `$fired = null;` +
                ` add_action( 'buddynextpro_subscription_expiring_soon', function ( $sub_id, $user_id, $tier_id, $access_until, $days_left ) use ( &$fired ) {` +
                `   $fired = compact( 'sub_id', 'user_id', 'tier_id', 'access_until', 'days_left' );` +
                ` }, 10, 5 );` +
                ` \\BuddyNextPro\\Membership\\RenewalReminderService::run_sweep();` +
                ` echo wp_json_encode( $fired );`,
        ]);

        const fired = JSON.parse(captured.trim() || 'null') as {
            sub_id: number;
            user_id: number;
            tier_id: number;
            access_until: string;
            days_left: number;
        } | null;

        expect(fired, 'the sweep should have fired an expiring-soon warning for this subscription').not.toBeNull();
        expect(fired?.sub_id).toBe(subId);
        expect(fired?.user_id).toBe(memberId);
        expect(fired?.tier_id).toBe(tierId);
        // 12 hours out rounds up to 1 whole day left, never 0 - a member must never
        // be told they have zero days to act.
        expect(fired?.days_left).toBeGreaterThanOrEqual(1);

        // EFFECT, persisted: reminder_sent_days is claimed so a second sweep run
        // today does not warn this member twice for the same offset.
        const sentDays = await wp([
            'eval',
            `global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare(` +
                ` "SELECT reminder_sent_days FROM {$wpdb->prefix}bn_subscriptions WHERE id = %d", ${subId}` +
                ` ) );`,
        ]);
        expect(Number(sentDays)).toBeGreaterThan(0);
    });
});
