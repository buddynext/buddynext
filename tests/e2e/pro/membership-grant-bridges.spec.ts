import { test, expect } from '@playwright/test';

import { loginAs } from '../_fixtures/actor';
import { wp, ensureUser } from '../_fixtures/wp';
import { softSkip } from '../_fixtures/precondition';

/**
 * J-975 a WooCommerce order grants the mapped BuddyNext plan, attributes it to
 * WooCommerce (not read as a comp), and records the sale as a payment.
 *
 * Covers: cap-grant-a-plan-from-woocommerce-or-paid-memberships-pro, cap-attribute-a-membership-granted-by-another-system, cap-see-one-member-s-payment-history
 * Roles: admin, member
 *
 * WooCommerceBridge (buddynext-pro/includes/Bridges/WooCommerceBridge.php) maps a
 * WC product to a plan via the `buddynextpro_bridge_map_woocommerce` option and
 * listens to `woocommerce_order_status_changed`. On a granting status it (a)
 * reconciles entitlement — do_action('buddynext_ability_granted', ..., 'woocommerce')
 * — which WebhookSubscriptionSync turns into a bn_subscriptions row with
 * source='woocommerce', and (b) records the sale into bn_invoices via
 * record_order_money(). Both halves are AbstractGrantBridge's contract, so this
 * fixture drives a REAL WC_Order through update_status('completed') rather than
 * simulating the grant action directly — it is the only way to prove the bridge's
 * OWN order-to-plan mapping, not just the shared webhook plumbing underneath it.
 *
 * Three capabilities share this one fixture because they are three views of the
 * same event: the plan being granted (admin's Current plan + member's own plan),
 * who is attributed as the biller (both the admin panel's "&middot; WooCommerce"
 * and the member's "billed through WooCommerce" sentence), and the resulting
 * charge showing up as a payment (admin's per-member Billing history AND the
 * member's own invoices table on Settings > Membership) — reusing it is not
 * corner-cutting, it is what makes the three assertions consistent with each
 * other rather than three independently-seeded numbers that could quietly drift.
 *
 * WooCommerce is active on this harness, so its path runs for real. Paid
 * Memberships Pro is not, so that half is soft-skipped below (PmproBridge shares
 * the same AbstractGrantBridge contract WooCommerceBridge is proven against here,
 * and its own level-mapping logic has PHPUnit coverage in
 * buddynext-pro/tests/Bridges/PmproBridgeTest.php).
 */
test.describe.configure({ mode: 'serial' });

const MEMBER = 'bn_e2e_woo_grant_member';
const ADMIN = process.env.BN_TEST_USER ?? 'varundubey';
const TIER_SLUG = 'e2e-woo-grant-tier';
const PRODUCT_TITLE = 'E2E Woo Grant Product';

type Fixture = {
    memberId: number;
    tierId: number;
    productId: number;
    orderId: number;
};

let fx: Fixture | null = null;

/** Run PHP through wp-cli and return trimmed stdout. */
async function php(code: string): Promise<string> {
    return (await wp(['eval', code])).trim();
}

test.beforeAll(async () => {
    test.skip(process.env.BN_PRO !== '1', 'Membership grant bridges ship in BuddyNext Pro.');

    const wcActive = (await php("echo defined('WC_VERSION') ? '1' : '0';")) === '1';
    test.skip(!wcActive, 'WooCommerce is not active on this harness — there is nothing to grant a plan from.');

    const memberId = await ensureUser(MEMBER, `${MEMBER}@example.test`, 'Woo Grant Member');
    await wp(['user', 'meta', 'update', String(memberId), 'bn_onboarding_complete', '1']);

    const out = await php(`
        global $wpdb;

        // Idempotent: drop any stale fixture from a previous run before creating.
        $old_tier = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}bn_membership_tiers WHERE slug = %s", '${TIER_SLUG}' ) );
        if ( $old_tier ) {
            $wpdb->delete( $wpdb->prefix . 'bn_subscriptions', array( 'tier_id' => $old_tier ) );
            $wpdb->delete( $wpdb->prefix . 'bn_invoices', array( 'plan_id' => $old_tier ) );
            $wpdb->delete( $wpdb->prefix . 'bn_membership_tiers', array( 'id' => $old_tier ) );
        }
        foreach ( get_posts( array( 'post_type' => 'product', 'title' => '${PRODUCT_TITLE}', 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => -1 ) ) as $stale_id ) {
            wp_delete_post( $stale_id, true );
        }

        $tiers   = new \\BuddyNextPro\\Membership\\MembershipTierService();
        $tier_id = $tiers->create_tier(
            '${TIER_SLUG}',
            'E2E Woo Grant Tier',
            '',
            0,
            array( 'status' => 'active', 'billing_type' => 'one_time', 'price' => 9.99, 'currency' => 'USD' )
        );

        $product = new WC_Product_Simple();
        $product->set_name( '${PRODUCT_TITLE}' );
        $product->set_regular_price( '9.99' );
        $product->set_status( 'publish' );
        $product->set_virtual( true );
        $product_id = $product->save();

        // The mapping is an owner-editable OPTION (not product meta) — see
        // WooCommerceBridge::save_product_field(). Writing it directly is the same
        // effect the product-edit screen's own save handler has.
        $map_option = 'buddynextpro_bridge_map_woocommerce';
        $map        = get_option( $map_option, array() );
        if ( ! is_array( $map ) ) { $map = array(); }
        $map[ (string) $product_id ] = '${TIER_SLUG}';
        update_option( $map_option, $map );

        // The real customer purchase, driven through WooCommerce's own order
        // lifecycle — update_status() fires 'woocommerce_order_status_changed',
        // which is what WooCommerceBridge actually listens to.
        $order = wc_create_order();
        $order->set_customer_id( ${memberId} );
        $order->add_product( wc_get_product( $product_id ), 1 );
        $order->calculate_totals();
        $order->update_status( 'completed' );

        echo wp_json_encode( array(
            'tierId'    => $tier_id,
            'productId' => $product_id,
            'orderId'   => $order->get_id(),
        ) );
    `);

    const parsed = JSON.parse(out.slice(out.indexOf('{'))) as { tierId: number; productId: number; orderId: number };
    fx = { memberId, ...parsed };

    expect(fx.orderId, 'the WooCommerce order must be created').toBeGreaterThan(0);
    expect(fx.tierId, 'the fixture plan must be created').toBeGreaterThan(0);
});

test.afterAll(async () => {
    if (!fx) {
        return;
    }
    await php(`
        global $wpdb;
        $order = wc_get_order( ${fx.orderId} );
        if ( $order ) { $order->delete( true ); }
        wp_delete_post( ${fx.productId}, true );
        $wpdb->delete( $wpdb->prefix . 'bn_subscriptions', array( 'tier_id' => ${fx.tierId} ) );
        $wpdb->delete( $wpdb->prefix . 'bn_invoices', array( 'plan_id' => ${fx.tierId} ) );
        $wpdb->delete( $wpdb->prefix . 'bn_membership_tiers', array( 'id' => ${fx.tierId} ) );
        $map = get_option( 'buddynextpro_bridge_map_woocommerce', array() );
        if ( is_array( $map ) ) {
            unset( $map[ (string) ${fx.productId} ] );
            update_option( 'buddynextpro_bridge_map_woocommerce', $map );
        }
    `);
});

test("J-975 admin sees the granted plan, its WooCommerce source, and the sale on the member's payment history", async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop', 'Owner admin journey runs at desktop.');
    if (!fx) {
        return;
    }

    await loginAs(page, ADMIN);
    await page.goto(`/wp-admin/admin.php?page=buddynext-members&view=edit-member&user_id=${fx.memberId}`, {
        waitUntil: 'domcontentloaded',
    });

    const currentPlan = page.locator('.bn-member-membership-list');
    await expect(currentPlan, 'the WooCommerce order granted the mapped plan').toContainText('E2E Woo Grant Tier');
    await expect(currentPlan, 'the plan is attributed to WooCommerce, not silently read as an admin comp').toContainText(
        'WooCommerce'
    );

    const billing = page.locator('.bn-member-billing-table');
    await expect(billing, "this member's payment history renders the sale").toBeVisible();
    await expect(billing).toContainText('E2E Woo Grant Tier');
    await expect(billing).toContainText('9.99');
});

test('J-975 the member sees their plan is billed through WooCommerce, with the same sale in their own history', async ({
    page,
}, testInfo) => {
    if (!fx) {
        return;
    }

    await loginAs(page, MEMBER);
    await page.goto('/settings/membership/', { waitUntil: 'domcontentloaded' });

    const reason = page.locator('.bn-my-membership__billed-elsewhere');
    await expect(reason, 'member is told this is billed elsewhere, not by BuddyNext').toBeVisible();
    await expect(reason).toContainText('billed through WooCommerce');

    await expect(
        page.locator('.bn-my-membership__cancel'),
        'no BuddyNext cancel control for a one-time purchase billed at WooCommerce'
    ).toHaveCount(0);
    await expect(page.getByRole('link', { name: /Manage in WooCommerce/i })).toBeVisible();

    const invoices = page.locator('.bn-my-membership__invoices');
    await expect(invoices, 'the member also sees their own payment history, not only the admin').toBeVisible();
    await expect(invoices).toContainText('9.99');

    if (testInfo.project.name === 'mobile') {
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        expect(overflow, 'the membership page fits the screen at 390px').toBeLessThanOrEqual(1);
    }
});

test('Paid Memberships Pro grant path', async ({}, testInfo) => {
    test.skip(process.env.BN_PRO !== '1', 'Membership grant bridges ship in BuddyNext Pro.');

    const pmproActive = (await php("echo defined('PMPRO_VERSION') ? '1' : '0';")) === '1';
    if (!pmproActive) {
        softSkip(
            testInfo,
            'Paid Memberships Pro is not installed on this harness; only the WooCommerce grant path above ' +
                'is exercised end to end. PmproBridge extends the same AbstractGrantBridge contract proven ' +
                "above (grant()/reconcile()/source declaration), and its own level-mapping logic has PHPUnit " +
                'coverage in buddynext-pro/tests/Bridges/PmproBridgeTest.php.'
        );
        return;
    }

    test.fixme(true, 'J-975: PMPro is active on this harness but this journey has not been written against a real level yet.');
});
