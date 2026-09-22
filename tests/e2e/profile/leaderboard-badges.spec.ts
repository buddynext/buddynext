import { test, expect } from '@playwright/test';

import { loginAs } from '../_fixtures/actor';
import { wp } from '../_fixtures/wp';

/**
 * J-814 leaderboard rank names its period, J-815 badge links land on the badge.
 *
 *   J-814  On every leaderboard tab the hero's rank label names that tab's period
 *          ("this month", "this week", "all time"), since the points beside it
 *          are all-time.
 *   J-815  A feed link to /achievements/#badge-{id} lands with that badge in view
 *          and highlighted, and a badge tile only links to its share page when
 *          the viewer can open it (owner or admin, or a published badge).
 *
 * Needs wb-gamification active; skipped otherwise.
 *
 * Covers: (none pinned - leaderboard rank + badge tiles are a wb-gamification
 * bridge feature; no CAPABILITIES.md row itemizes leaderboards or badges)
 * Roles: member, anon
 * Note: memberLogin is deliberately a seeded member (user_id > 1, never the
 * admin fixture); J-815 also drops to a logged-out visitor to check published-
 * only badge links.
 */

test.describe.configure({ mode: 'serial' });

let memberLogin = '';
let badgeId = '';
let publishedCount = 0;

async function php(code: string): Promise<string> {
    return ((await wp(['eval', code])).trim().split('\n').pop() ?? '').trim();
}

test.beforeAll(async () => {
    const active = await php(`echo function_exists( 'wb_gam_get_leaderboard' ) ? 1 : 0;`);
    test.skip(active !== '1', 'wb-gamification is not active.');

    const pick = JSON.parse(
        await php(`
            global $wpdb;
            $row = $wpdb->get_row( "SELECT b.user_id, b.badge_id FROM {$wpdb->prefix}wb_gam_user_badges b JOIN {$wpdb->users} u ON u.ID = b.user_id WHERE b.user_id > 1 ORDER BY b.user_id LIMIT 1", ARRAY_A );
            $login = $row ? get_userdata( (int) $row['user_id'] )->user_login : '';
            $published = $row ? count( \\WBGam\\Engine\\BadgeShare::shared_badges( (int) $row['user_id'] ) ) : 0;
            if ( $row ) { update_user_meta( (int) $row['user_id'], 'bn_onboarding_complete', '1' ); }
            echo wp_json_encode( array( 'login' => $login, 'badge' => $row['badge_id'] ?? '', 'published' => $published ) );
        `)
    );
    memberLogin = pick.login;
    badgeId = pick.badge;
    publishedCount = Number(pick.published);
    test.skip(!memberLogin || !badgeId, 'No member with an earned badge on this site.');
});

test('J-814 the hero rank label names the selected period', async ({ page }) => {
    await loginAs(page, memberLogin);
    const expected: Record<string, RegExp> = {
        month: /your rank this month/i,
        week: /your rank this week/i,
        alltime: /your rank all time/i,
    };
    for (const [period, label] of Object.entries(expected)) {
        await page.goto(`/activity/leaderboard/?period=${period}`);
        await expect(page.locator('.bn-lb-hero .bn-stat__label').first(), `label on the ${period} tab`).toHaveText(label);
    }
    // Default tab is the month.
    await page.goto('/activity/leaderboard/');
    await expect(page.locator('.bn-lb-hero .bn-stat__label').first()).toHaveText(expected.month);
});

test('J-815 a badge link lands on the badge, and tiles link only where they open', async ({ page }) => {
    await loginAs(page, memberLogin);
    await page.goto(`/members/${memberLogin}/achievements/#badge-${badgeId}`);
    const tile = page.locator(`li#badge-${badgeId}`);
    await expect(tile, 'the badge anchor exists').toHaveCount(1);
    await expect(tile, 'the linked badge is in view').toBeInViewport();
    const highlighted = await tile.locator('.bn-achievements__badge-link').evaluate((el) => getComputedStyle(el).boxShadow);
    expect(highlighted, 'the linked badge is highlighted').not.toBe('none');

    // The owner can open their earned badges' share pages.
    expect(await page.locator('a.bn-achievements__badge-link').count(), 'owner: earned tiles link').toBeGreaterThan(0);

    // A logged-out visitor only gets links for published badges.
    await page.context().clearCookies();
    await page.goto(`/members/${memberLogin}/achievements/`);
    await expect(page.locator('li[id^="badge-"]').first()).toBeAttached();
    expect(await page.locator('a.bn-achievements__badge-link').count(), 'visitor: only published badges link').toBe(publishedCount);
});
