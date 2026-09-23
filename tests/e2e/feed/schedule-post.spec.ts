import { test, expect } from '../_fixtures/auth.fixture';
import { loginAs } from '../_fixtures/actor';
import { sel, urls } from '../_fixtures/selectors';
import { readRestNonce } from '../_fixtures/feed-wave1.helpers';
import { wp, dbScalar, tablePrefix, userId } from '../_fixtures/wp';

const MEMBER_LOGIN = process.env.BN_TEST_OTHER_USER ?? 'alice';

/**
 * J-512 composer schedule-a-post (B1).
 *
 * Written from the member's promise: "a post I schedule for later is NOT in the
 * feed now, and the server is holding it as scheduled." The coverage matrix had
 * "Schedule post" MISSING.
 *
 * Effect-based via DB truth: after composing with a future publish datetime the
 * spec reads the bn_posts row directly and asserts status='scheduled' with a
 * future scheduled_at, AND that the card is absent from the current home feed.
 * A composer that ignores the schedule field and publishes immediately (status
 * 'published', card visible now) fails both legs. The row is deleted in
 * `finally` by id so a scheduled post never lingers in the seed.
 *
 * Covers: cap-schedule-a-post-to-publish-later
 * Roles: admin, member
 */
test.describe('feed / composer schedule', () => {
    // Composer toolbar schedule tool + its datetime field (partials/composer.php).
    const scheduleTool = 'button.bn-composer__tool[aria-label="Schedule for later"]';
    const scheduleInput = '#bn-composer-schedule-at';

    test('J-512 a scheduled post is held (status=scheduled) and stays out of the feed now', async ({ authenticatedPage: page }) => {
        const stamp = Date.now().toString().slice(-6);
        const content = `j512 scheduled ${stamp}`;
        let postId = 0;

        try {
            await page.goto(urls.feed);
            const composer = page.locator(sel.composer).first();
            await expect(composer).toBeVisible();
            await readRestNonce(page); // asserts the feed carries a live nonce

            const ta = page.locator(sel.composerTextarea).first();
            await ta.fill(content);

            // Open the schedule affordance and set a far-future publish datetime
            // (site wall-clock; the store converts it to UTC on input).
            await page.locator(scheduleTool).first().click();
            const dt = page.locator(scheduleInput).first();
            await expect(dt).toBeVisible({ timeout: 5_000 });
            await dt.fill('2035-06-01T10:00');

            await page.locator(sel.composerSubmit).first().click();

            // Success clears the composer content (no card is prepended for a
            // scheduled post — it is not live). Wait for the textarea to empty.
            await expect(ta).toHaveValue('', { timeout: 10_000 });

            // Effect 1 — the row exists as SCHEDULED with a future publish time.
            const p = await tablePrefix();
            // wp-cli db query with LIKE; content is unique by stamp.
            const row = await dbScalar(
                `SELECT CONCAT(id,'|',status,'|',COALESCE(scheduled_at,'')) FROM ${p}bn_posts WHERE content LIKE '%${content}%' ORDER BY id DESC LIMIT 1;`
            );
            expect(row, 'scheduled row exists').not.toBe('');
            const [idStr, status, schedAt] = row.split('|');
            postId = parseInt(idStr, 10) || 0;
            expect(status, 'stored status').toBe('scheduled');
            expect(schedAt, 'scheduled_at is set').not.toBe('');
            expect(new Date(schedAt.replace(' ', 'T') + 'Z').getTime()).toBeGreaterThan(Date.now());

            // Effect 2 — it is NOT in the live home feed right now.
            await page.goto(urls.feed);
            await expect(page.locator(sel.postCard).filter({ hasText: content })).toHaveCount(0);
        } finally {
            if (postId > 0) {
                const p = await tablePrefix();
                await wp(['db', 'query', `DELETE FROM ${p}bn_posts WHERE id=${postId};`]).catch(() => '');
            }
        }
    });

    /**
     * J-512 member leg. Scheduling is the member's own composer promise
     * ("a post I schedule for later is NOT in the feed now") — the admin walk
     * bypasses no capability here, but a member is who actually plans posts
     * ahead, so a regression scoped to the non-admin write path (e.g. an owner
     * check on the scheduled row) would only show up walking as a member.
     *
     * On a Pro + monetization site, scheduling is plan-gated: Free's
     * 'buddynext-feed/schedule-post' capability is answered by the member's
     * plan via EntitlementGates::gate_capability(), and the catalogue default
     * for 'content.scheduled_posts' is false (EntitlementRegistry.php) - a
     * deliberate Pro monetization gate, not a bug. The admin leg above passes
     * without any of this because EntitlementGates::is_exempt() bypasses the
     * gate for admins/owners; a plain member is not exempt. So this leg grants
     * the member an active subscription on a throwaway tier that explicitly
     * includes the entitlement, matching how membership-explore-gate.spec.ts
     * proves an analogous plan-gated capability, then removes only that
     * subscription + tier in `finally` - MEMBER_LOGIN is a shared fixture user
     * reused across the suite and must come out exactly as it went in.
     */
    test('J-512 member  -  a member-scheduled post is held and stays out of the feed now', async ({ page }) => {
        const memberId = await userId(MEMBER_LOGIN);
        expect(memberId, `member "${MEMBER_LOGIN}" must resolve to a user id`).toBeGreaterThan(0);

        const TIER_SLUG = 'bn-e2e-schedule-post-plan';
        let tierId = 0;
        if (process.env.BN_PRO === '1') {
            tierId = Number(
                await wp([
                    'eval',
                    `if ( ! class_exists( '\\\\BuddyNextPro\\\\Membership\\\\MembershipTierService' ) ) { echo 0; exit; }` +
                        ` $svc = new \\BuddyNextPro\\Membership\\MembershipTierService();` +
                        ` $existing = $svc->get_tier_by_slug( '${TIER_SLUG}' ); if ( $existing ) { $svc->delete_tier( (int) $existing['id'] ); }` +
                        ` echo (int) $svc->create_tier( '${TIER_SLUG}', 'E2E Schedule Post Plan', '', 0, array(` +
                        `   'status' => 'active', 'price' => 1.0, 'billing_type' => 'recurring', 'billing_interval' => 'month',` +
                        `   'entitlements' => array( 'content.scheduled_posts' => true )` +
                        ` ) );`,
                ]).catch(() => '0')
            );
            if (tierId > 0) {
                await wp([
                    'eval',
                    `$sub = new \\BuddyNextPro\\Membership\\SubscriptionService();` +
                        ` $exp = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );` +
                        ` $sub->create_subscription( ${memberId}, ${tierId}, 'manual', $exp, '', 'active' );` +
                        ` \\BuddyNextPro\\Membership\\MembershipCapabilities::flush();`,
                ]);
            }
        }

        await loginAs(page, MEMBER_LOGIN);
        const stamp = Date.now().toString().slice(-6);
        const content = `j512m member scheduled ${stamp}`;
        let postId = 0;

        try {
            await page.goto(urls.feed);
            const composer = page.locator(sel.composer).first();
            await expect(composer).toBeVisible();
            await readRestNonce(page);

            const ta = page.locator(sel.composerTextarea).first();
            await ta.fill(content);

            await page.locator(scheduleTool).first().click();
            const dt = page.locator(scheduleInput).first();
            await expect(dt).toBeVisible({ timeout: 5_000 });
            await dt.fill('2035-06-01T10:00');

            await page.locator(sel.composerSubmit).first().click();
            await expect(ta).toHaveValue('', { timeout: 10_000 });

            const p = await tablePrefix();
            const row = await dbScalar(
                `SELECT CONCAT(id,'|',status,'|',COALESCE(scheduled_at,'')) FROM ${p}bn_posts WHERE content LIKE '%${content}%' ORDER BY id DESC LIMIT 1;`
            );
            expect(row, 'scheduled row exists').not.toBe('');
            const [idStr, status, schedAt] = row.split('|');
            postId = parseInt(idStr, 10) || 0;
            expect(status, 'stored status').toBe('scheduled');
            expect(schedAt, 'scheduled_at is set').not.toBe('');
            expect(new Date(schedAt.replace(' ', 'T') + 'Z').getTime()).toBeGreaterThan(Date.now());

            await page.goto(urls.feed);
            await expect(page.locator(sel.postCard).filter({ hasText: content })).toHaveCount(0);
        } finally {
            if (postId > 0) {
                const p = await tablePrefix();
                await wp(['db', 'query', `DELETE FROM ${p}bn_posts WHERE id=${postId};`]).catch(() => '');
            }
            if (tierId > 0) {
                await wp([
                    'eval',
                    `global $wpdb;` +
                        ` $wpdb->delete( $wpdb->prefix . 'bn_subscriptions', array( 'tier_id' => ${tierId}, 'user_id' => ${memberId} ) );` +
                        ` ( new \\BuddyNextPro\\Membership\\MembershipTierService() )->delete_tier( ${tierId} );` +
                        ` \\BuddyNextPro\\Membership\\MembershipCapabilities::flush();`,
                ]).catch(() => undefined);
            }
        }
    });
});
