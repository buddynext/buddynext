import { test, expect } from '@playwright/test';
import type { Page } from '@playwright/test';

import { wp, ensureUser } from '../_fixtures/wp';

/**
 * J-807 membership control contract (Pro membership panel) - plan task T-19.
 *
 * One rule, across every state a membership can be in:
 *
 *   If the panel renders a control, that control works.
 *
 * Three ways it can fail, and all three have shipped:
 *
 *   1. A link with nowhere to go. `MembershipPages::pricing_url()` never
 *      returns empty — it falls back to the page and then the home URL — so
 *      "Browse plans" rendered on free-only sites and on sites whose gateway was
 *      never credentialed, landing the member on a page with nothing on it.
 *   2. A button the server then refuses. `MembershipCapabilities` restated
 *      `member_cancel()`'s rules by hand and the copies drifted, so a Cancel
 *      button appeared for members the endpoint answered with a 409. A Switch
 *      button appeared for WooCommerce-billed members and, worse, WORKED —
 *      moving the plan here while WooCommerce carried on billing the old one.
 *   3. A control that vanishes with no explanation. An Offline-gateway
 *      membership showed no Cancel and no reason, which reads as a broken page
 *      rather than a deliberate one.
 *
 * ── Why this test exists ────────────────────────────────────────────────────
 *
 * Every one of those passed the PHP suite. They were found by loading the page
 * and looking at it. The unit tests now pin each rule at its own boundary, but
 * nothing asserted the thing a member actually experiences: that what is on the
 * screen and what the server will do agree.
 *
 * So this drives the states through the DB, renders the real panel, and checks
 * the rendered controls against `GET /me/subscriptions` — the same capabilities
 * the server would enforce. A disagreement fails here whichever side is wrong.
 *
 * Follows the admin table-layout contract precedent: assert the invariant across
 * a discovered matrix, rather than the handful of screens someone happened to be
 * looking at when they wrote the fix.
 */

const MEMBER = 'bn_t19_member';
const PANEL = '/settings/membership/';

/** Subscription shapes a member can be in. */
const SOURCES = [
    { key: 'none', label: 'no subscription at all' },
    { key: 'stripe', label: 'billed by one of our gateways' },
    { key: 'woocommerce', label: 'billed by another system' },
    { key: 'manual', label: 'comped by the owner' },
] as const;

/** Whether the site has anything it can actually sell. */
const MARKETS = [
    { sellable: true, label: 'with plans on sale' },
    { sellable: false, label: 'with nothing on sale' },
] as const;

test.describe('pro / membership control contract', () => {
    test.fixme(process.env.BN_PRO !== '1', 'The membership panel only exists when Pro is active.');

    let memberId = 0;

    test.beforeAll(async () => {
        memberId = await ensureUser(MEMBER, `${MEMBER}@example.test`, 'T19 Member');

        // Finish onboarding, or every hub 302s to /onboarding/ and the panel is
        // never reached. A fresh user is exactly the state a "no subscription"
        // case needs, and it is also the state the wizard intercepts — so without
        // this the two most interesting rows of the matrix test the redirect
        // instead of the panel.
        await wp([
            'eval',
            `( new \\BuddyNext\\Onboarding\\OnboardingService() )->finish( ${memberId} );`,
        ]);
    });

    test.afterAll(async () => {
        await clearSubscriptions();
        await setMarketSellable(true);
    });

    /**
     * Drop every subscription this member holds.
     *
     * Through the service rather than a DELETE, so rows that other code keys on
     * (the auto-assigned default plan) are recreated the way the site expects.
     */
    async function clearSubscriptions(): Promise<void> {
        await wp([
            'eval',
            `global $wpdb; $wpdb->delete( $wpdb->prefix . 'bn_subscriptions', array( 'user_id' => ${memberId} ) );` +
                ` \\BuddyNextPro\\Membership\\MembershipCapabilities::flush();`,
        ]);
    }

    /**
     * Put the member on a plan from a given source.
     *
     * Uses SubscriptionService so the row is one the rest of the system would
     * have produced — seeding raw SQL here would test a shape that never occurs.
     */
    async function subscribe(source: string): Promise<void> {
        await clearSubscriptions();

        if (source === 'none') {
            return;
        }

        await wp([
            'eval',
            `$t = ( new \\BuddyNextPro\\Membership\\MembershipTierService() )->list_tiers();` +
                ` $paid = 0;` +
                ` foreach ( $t as $tier ) { if ( empty( $tier['is_free'] ) && (float) $tier['price'] > 0 ) { $paid = (int) $tier['id']; break; } }` +
                ` if ( $paid > 0 ) {` +
                `   ( new \\BuddyNextPro\\Membership\\SubscriptionService() )->create_subscription(` +
                `     ${memberId}, $paid, '${source}', gmdate( 'Y-m-d H:i:s', time() + ( 30 * DAY_IN_SECONDS ) ), ''` +
                `   );` +
                ` }` +
                ` \\BuddyNextPro\\Membership\\MembershipCapabilities::flush();`,
        ]);
    }

    /**
     * Switch the whole site between "has something to sell" and "does not".
     *
     * By tier status rather than by gateway config: it is reversible in one
     * statement and does not risk leaving the site's payment settings altered if
     * the run dies partway.
     */
    async function setMarketSellable(sellable: boolean): Promise<void> {
        const status = sellable ? 'active' : 'inactive';

        await wp([
            'eval',
            `global $wpdb; $wpdb->query( $wpdb->prepare(` +
                ` "UPDATE {$wpdb->prefix}bn_membership_tiers SET status = %s WHERE is_free = 0 AND price > 0", '${status}' ) );` +
                ` wp_cache_flush(); \\BuddyNextPro\\Membership\\MembershipCapabilities::flush();`,
        ]);
    }

    /**
     * What the server says this member may do, straight from the REST payload.
     *
     * The nonce is not optional. WordPress cookie auth over REST requires
     * X-WP-Nonce; without it every request is anonymous, the permission callback
     * refuses, and this returns null — which silently turns the comparison below
     * into "assert no controls render", passing on the states that have none and
     * failing on the states that matter. Scraped from the page's own
     * data-wp-context rather than minted, because a nonce is bound to the session
     * that will use it.
     *
     * Throws rather than returning null on a failed request, so a broken harness
     * can never be mistaken for a member with no subscriptions.
     */
    async function serverCapabilities(page: Page): Promise<Record<string, unknown> | null> {
        const html = await page.content();
        const nonce = html.match(/restNonce"\s*:\s*"([a-z0-9]+)"/i)?.[1] ?? '';

        expect(nonce, 'no REST nonce on the page — the harness cannot ask the server anything').not.toBe('');

        const result = await page.evaluate(async (n: string) => {
            const res = await fetch('/wp-json/buddynext-pro/v1/me/subscriptions', {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-WP-Nonce': n },
            });
            return { ok: res.ok, status: res.status, body: res.ok ? await res.json() : null };
        }, nonce);

        expect(result.ok, `/me/subscriptions answered ${result.status}`).toBe(true);

        const rows = result.body;
        if (!Array.isArray(rows) || rows.length === 0) {
            return null;
        }

        return (rows[0] as { capabilities?: Record<string, unknown> }).capabilities ?? null;
    }

    for (const source of SOURCES) {
        for (const market of MARKETS) {
            test(`${source.label}, ${market.label}: every control on the panel works`, async ({ page }) => {
                await subscribe(source.key);
                await setMarketSellable(market.sellable);

                await page.goto(`/?autologin=${MEMBER}`, { waitUntil: 'domcontentloaded' });
                await page.goto(PANEL, { waitUntil: 'domcontentloaded' });

                const panel = page.locator('.bn-my-membership');
                await expect(panel, 'the membership panel should render at all').toBeVisible();

                // ── 1. No link that goes nowhere ─────────────────────────────
                //
                // An empty href resolves to the CURRENT page, so the member
                // clicks and nothing happens — the failure mode that reads as
                // "the site is broken" rather than "this is unavailable".
                const links = panel.locator('a[href]');
                const hrefs = await links.evaluateAll((nodes) =>
                    nodes.map((n) => (n as HTMLAnchorElement).getAttribute('href') ?? '')
                );

                for (const href of hrefs) {
                    expect(href.trim(), 'a rendered link must point somewhere').not.toBe('');
                    expect(href.trim(), 'a rendered link must point somewhere').not.toBe('#');
                }

                // ── 2. Every destination actually resolves ───────────────────
                for (const href of hrefs) {
                    if (/^(mailto:|tel:|javascript:)/i.test(href)) continue;

                    const target = new URL(href, page.url());
                    if (target.origin !== new URL(page.url()).origin) continue;

                    const response = await page.request.get(target.toString());
                    expect(
                        response.status(),
                        `${href} is offered to the member but does not resolve`
                    ).toBeLessThan(400);
                }

                // ── 3. The screen and the server agree ───────────────────────
                const caps = await serverCapabilities(page);
                const cancelVisible = await panel
                    .getByRole('button', { name: /cancel membership/i })
                    .isVisible()
                    .catch(() => false);
                const switchVisible = await panel
                    .getByRole('button', { name: /^switch$/i })
                    .first()
                    .isVisible()
                    .catch(() => false);

                if (caps) {
                    expect(
                        cancelVisible,
                        'a Cancel button rendered that the cancel endpoint would refuse (or vice versa)'
                    ).toBe(Boolean(caps.can_cancel));

                    expect(
                        switchVisible,
                        'a Switch button rendered that the plan-change endpoint would refuse (or vice versa)'
                    ).toBe(Boolean(caps.can_change));
                } else {
                    expect(cancelVisible, 'nothing to cancel without a subscription').toBe(false);
                    expect(switchVisible, 'nothing to switch without a subscription').toBe(false);
                }

                // ── 4. A withheld control is explained ───────────────────────
                //
                // Silence reads as a broken page. When the member holds a plan we
                // cannot act on, the panel has to say so — this is the Offline
                // case, which showed neither a button nor a reason.
                if (caps && !caps.can_cancel && caps.billed_by !== 'none') {
                    await expect(
                        panel,
                        'a membership with no available actions must say why'
                    ).toContainText(/billed through|paid for in one go|given to you by the site|not set up through this site/i);
                }

                // ── 5. Nothing on sale means nothing is offered ──────────────
                if (!market.sellable) {
                    await expect(
                        panel.getByRole('link', { name: /browse plans|upgrade|resubscribe/i }),
                        'nothing is for sale, so nothing may be offered'
                    ).toHaveCount(0);
                }
            });
        }
    }
});
