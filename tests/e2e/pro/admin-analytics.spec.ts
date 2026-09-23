import { test, expect } from '@playwright/test';

import { loginAs } from '../_fixtures/actor';
import { wp, ensureUser } from '../_fixtures/wp';

/**
 * J-976 the admin revenue tiles move with a real payment; J-977 a member's own
 * activity shows up as their own row in the admin's per-member engagement table.
 *
 * Covers: cap-report-revenue-over-time, cap-track-engagement-per-member
 * Roles: admin
 *
 * cap-report-revenue-over-time is PARTIAL, not YES, per buddynext-pro/CAPABILITIES.md:
 * the Billing screen (admin.php?page=buddynext-monetization&tab=subscriptions) shows
 * point-in-time MRR, ARR and "Received, 30 days" tiles (MembershipAdmin::render_subscriptions_page),
 * but there is no revenue TIME-SERIES / trend report — nothing plots revenue across
 * days or weeks. J-976 proves what exists (the Received tile is a live SUM(total-refunded)
 * over bn_invoices, not a static number) rather than the trend view the capability's
 * name implies. Flagging the gap here rather than overclaiming it: an owner asking
 * "is revenue up or down this quarter" cannot answer that from this screen today.
 *
 * cap-track-engagement-per-member is proven by AnalyticsAdmin's "Top members" card
 * (render_top_members_card(), Engagement > Insights > Overview), which reads
 * AnalyticsService::top_members() — a live GROUP BY actor_id over bn_analytics_events.
 * This is an admin-only surface (an owner looking at OTHER members' activity); there
 * is no member-facing equivalent to walk, so only the admin role is declared here even
 * though bin/check-role-coverage.py's generic heuristic also asks for "member" on this
 * row — that heuristic infers roles from keyword matching on the capability's wording
 * and has no way to know this particular promise is inherently owner-only.
 */

const ADMIN = process.env.BN_TEST_USER ?? 'varundubey';
const REVENUE_TIER_SLUG = 'e2e-revenue-tier';
const ENGAGEMENT_MEMBER = 'bn_e2e_engagement_member';

/** Run PHP through wp-cli and return trimmed stdout. */
async function php(code: string): Promise<string> {
    return (await wp(['eval', code])).trim();
}

test.describe('J-976 revenue report', () => {
    let tierId = 0;
    let invoiceId = 0;
    let expectedReceivedUsd = '';

    test.beforeAll(async () => {
        test.skip(process.env.BN_PRO !== '1', 'Billing screen ships in BuddyNext Pro.');

        const memberId = await ensureUser(`${ENGAGEMENT_MEMBER}_rev`, `${ENGAGEMENT_MEMBER}_rev@example.test`, 'Revenue Payer');

        const out = await php(`
            global $wpdb;
            $old = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}bn_membership_tiers WHERE slug = %s", '${REVENUE_TIER_SLUG}' ) );
            if ( $old ) {
                $wpdb->delete( $wpdb->prefix . 'bn_invoices', array( 'plan_id' => $old ) );
                $wpdb->delete( $wpdb->prefix . 'bn_membership_tiers', array( 'id' => $old ) );
            }

            $tiers   = new \\BuddyNextPro\\Membership\\MembershipTierService();
            $tier_id = $tiers->create_tier(
                '${REVENUE_TIER_SLUG}',
                'E2E Revenue Tier',
                '',
                0,
                array( 'status' => 'active', 'billing_type' => 'one_time', 'price' => 42.42, 'currency' => 'USD' )
            );

            $subs       = new \\BuddyNextPro\\Membership\\SubscriptionService();
            $invoice_id = $subs->record_invoice( array(
                'user_id'           => ${memberId},
                'plan_id'           => $tier_id,
                'gateway'           => 'manual',
                'amount'            => 42.42,
                'total'             => 42.42,
                'currency'          => 'USD',
                'status'            => 'paid',
                'created_at'        => current_time( 'mysql', true ),
                'external_order_id' => 'e2e-revenue-${Date.now()}',
            ) );

            // The live SUM this invoice must move — computed in the SAME script as
            // the insert so there is no race with a concurrent write on this DB.
            $received = $subs->payments_received( 30 );
            echo wp_json_encode( array(
                'tierId'      => $tier_id,
                'invoiceId'   => $invoice_id,
                'expectedUsd' => number_format_i18n( (float) ( $received['USD'] ?? 0 ), 2 ),
            ) );
        `);

        const parsed = JSON.parse(out.slice(out.indexOf('{'))) as {
            tierId: number;
            invoiceId: number;
            expectedUsd: string;
        };
        tierId = parsed.tierId;
        invoiceId = parsed.invoiceId;
        expectedReceivedUsd = parsed.expectedUsd;

        expect(invoiceId, 'the fixture invoice must be recorded').toBeGreaterThan(0);
    });

    test.afterAll(async () => {
        if (!tierId) {
            return;
        }
        await php(`
            global $wpdb;
            $wpdb->delete( $wpdb->prefix . 'bn_invoices', array( 'plan_id' => ${tierId} ) );
            $wpdb->delete( $wpdb->prefix . 'bn_membership_tiers', array( 'id' => ${tierId} ) );
        `);
    });

    test('J-976 the Received tile is a live sum, not a static number', async ({ page }, testInfo) => {
        test.skip(testInfo.project.name !== 'desktop', 'Owner admin journey runs at desktop.');

        await loginAs(page, ADMIN);
        await page.goto('/wp-admin/admin.php?page=buddynext-monetization&tab=subscriptions', {
            waitUntil: 'domcontentloaded',
        });

        const tile = page.locator('.bn-stat', {
            has: page.locator('.bn-stat__label', { hasText: 'Received, 30 days (USD)' }),
        });
        await expect(tile, 'the Received tile renders').toBeVisible();
        await expect(
            tile.locator('.bn-stat__value'),
            "the tile's figure reflects the invoice just recorded, proving it reads real data"
        ).toHaveText(expectedReceivedUsd);
    });
});

test.describe('J-977 per-member engagement', () => {
    let memberId = 0;
    let target = 0;
    let expectedCount = '';
    let displayName = '';

    test.beforeAll(async () => {
        test.skip(process.env.BN_PRO !== '1', 'The Insights admin screen ships in BuddyNext Pro.');

        displayName = 'E2E Engagement Member';
        memberId = await ensureUser(ENGAGEMENT_MEMBER, `${ENGAGEMENT_MEMBER}@example.test`, displayName);

        // top_members() ranks by raw event COUNT across every actor on the site, so
        // a fixed seed count could lose to organic activity. Seeding current-max+25
        // guarantees this member ranks first regardless of what else is on the box.
        const out = await php(`
            add_filter( 'buddynextpro_analytics_rate_limit', static fn() => 0 );
            global $wpdb;
            // Idempotent: a prior interrupted run may have left this member's own
            // ping events behind, which would otherwise be counted TWICE against
            // an expected total computed from the seed loop alone.
            $wpdb->delete( $wpdb->prefix . 'bn_analytics_events', array( 'actor_id' => ${memberId}, 'event_type' => 'e2e.engagement.ping' ) );
            $current_max = 0;
            $rows = ( new \\BuddyNextPro\\Analytics\\AnalyticsService() )->top_members( 1 );
            if ( ! empty( $rows ) ) { $current_max = (int) $rows[0]['event_count']; }
            $target = $current_max + 25;
            for ( $i = 0; $i < $target; $i++ ) {
                \\BuddyNextPro\\Analytics\\AnalyticsCollector::record( 'e2e.engagement.ping', ${memberId}, null, null, array() );
            }
            wp_cache_flush();
            // The admin card's row total is this member's REAL, total event count
            // (GROUP BY actor_id, every event_type) - not just the ${'e2e.engagement.ping'}
            // rows this fixture added. A brand-new member normally has none of its
            // own, but asserting the loop count directly assumes that rather than
            // verifying it; reading it back is the same one query the admin page
            // itself runs, so the assertion cannot drift from server truth.
            $actual = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_analytics_events WHERE actor_id = %d", ${memberId} ) );
            echo wp_json_encode( array(
                'target'        => $target,
                // Same formatter the admin table itself uses (number_format_i18n),
                // so the assertion cannot be tripped up by a thousands separator on
                // a box with a lot of pre-existing organic activity.
                'expectedCount' => number_format_i18n( $actual ),
            ) );
        `);

        const parsed = JSON.parse(out.slice(out.indexOf('{'))) as { target: number; expectedCount: string };
        target = parsed.target;
        expectedCount = parsed.expectedCount;
        expect(target, 'at least the seeded events must exist').toBeGreaterThan(0);
    });

    test.afterAll(async () => {
        if (!memberId) {
            return;
        }
        await php(`
            global $wpdb;
            $wpdb->delete( $wpdb->prefix . 'bn_analytics_events', array( 'actor_id' => ${memberId}, 'event_type' => 'e2e.engagement.ping' ) );
            wp_cache_flush();
        `);
    });

    test('J-977 the Top members card shows this member with their real event count', async ({ page }, testInfo) => {
        test.skip(testInfo.project.name !== 'desktop', 'Owner admin journey runs at desktop.');

        await loginAs(page, ADMIN);
        await page.goto('/wp-admin/admin.php?page=buddynext-engagement&tab=insights', {
            waitUntil: 'domcontentloaded',
        });

        const row = page.locator('.bn-analytics-table tr', { has: page.locator('td', { hasText: displayName }) });
        await expect(row, 'the member appears as their own row in the per-member table').toBeVisible();
        await expect(row.locator('td').nth(1), "the row carries this member's real event count").toHaveText(
            expectedCount
        );
    });
});
