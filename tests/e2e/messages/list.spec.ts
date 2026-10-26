import { test, expect } from '../_fixtures/auth.fixture';
import { sel, urls } from '../_fixtures/selectors';
import { dbCount, tablePrefix } from '../_fixtures/wp';
import { dbSeedingAvailable } from '../_fixtures/db.fixture';
import { idsOf, restJson } from '../_fixtures/effects';

/**
 * J-45 DM list.
 *
 * Covers: cap-integrate-with-wbcom-plugins
 * Roles: member
 *
 * No free CAPABILITIES.md row covers in-app messaging itself (the only DM row
 * is "Run direct messaging on its own? NO"); the nearest verifiable promise is
 * the WPMediaVerse bridge row under Integrations, which is what this spec
 * actually proves (bridge-active UI, and clean degrade when inactive).
 *
 * DM is owned by WPMediaVerse; BuddyNext consumes it through a bridge. So there
 * are two shipping configurations, and the honest test asserts whichever one is
 * actually present rather than demanding the same outcome from both.
 *
 * The previous version claimed to cover both ("with or without DM bridge
 * active") and passed for the wrong reason: its final assertion accepted
 * `url.includes('messages')`, which is true merely by having navigated there,
 * and WPMediaVerse happened to be active on every machine that ran it. The
 * first run with the bridge deactivated failed - not because BuddyNext was
 * broken, but because the spec had never executed its own second case.
 *
 * Bridge absent, the product degrades deliberately: no Messages entry is
 * rendered anywhere in the navigation, and /messages/ redirects to the feed.
 * That is the contract worth protecting - a nav item that bounces you would be
 * the real bug, and nothing was asserting its absence.
 */
test.describe('messages / list', () => {
    test('DM list renders when the bridge is active, and degrades cleanly when it is not', async ({ authenticatedPage: page }) => {
        const resp = await page.goto(urls.messages, { waitUntil: 'domcontentloaded' });
        expect(resp?.status() ?? 200).toBeLessThan(500);
        await expect(page.locator(sel.app)).toBeVisible();

        const onMessages = page.url().includes('messages');

        if (onMessages) {
            // Bridge active: the route stays put and must show real UI - a list
            // or an explicit empty state. Landing on the URL is not evidence.
            const list = page.locator(sel.dmList).first();
            // Empty states in the live markup: the rail's `.bn-dm-rail__empty` (parts/dm-rail.php:252)
            // and the thread pane's `.bn-dm-empty` (messages/native.php:412). The old
            // `.bn-messages-empty / .bn-messages-placeholder / [data-dm-empty]` matched nothing.
            const placeholder = page.locator(`${sel.dmEmpty}, .bn-dm-empty`).first();
            const anyVisible =
                (await list.isVisible().catch(() => false)) ||
                (await placeholder.isVisible().catch(() => false));
            expect(anyVisible, 'DM route resolved but rendered neither a thread list nor an empty state').toBeTruthy();
            // EFFECT: the list reflects the message store: threads on screen only when conversations exist.
            if (dbSeedingAvailable()) {
                const rows = await page.locator('.bn-dm-rail__item, [data-dm-item]').count();
                const p = await tablePrefix();
                const conversations = await dbCount(`SELECT COUNT(*) FROM ${p}mvs_conversations`);
                if (rows > 0) {
                    expect(conversations, `${rows} threads on screen but the conversations table is empty`).toBeGreaterThan(0);
                }
            }
            // EFFECT: the rail lists the conversations the member's own DM route answers
            // (WPMediaVerse owns the store: mvs/v1). Both empty or both populated, and no
            // thread on screen that the route does not know about.
            const rest = await restJson(page, '/wp-json/mvs/v1/me/conversations?per_page=50');
            if (rest.status === 200) {
                const routeIds = idsOf(rest.data);
                const railIds = (await page.locator('.bn-dm-rail__item[data-conv-id]').evaluateAll((els) =>
                    els.map((el) => Number(el.getAttribute('data-conv-id')))
                )) as number[];
                expect(railIds.length > 0, `rail shows ${railIds.length} threads, route answers ${routeIds.length}`).toBe(routeIds.length > 0);
                if (routeIds.length < 50) {
                    expect(railIds.filter((id) => !routeIds.includes(id)), 'rail shows a thread the route does not list').toEqual([]);
                }
                // An empty inbox says so, rather than rendering a blank rail.
                if (routeIds.length === 0) {
                    await expect(page.locator(sel.dmEmpty).first()).toBeVisible();
                }
            } else {
                // Messaging switched off in WPMediaVerse: the page must say so, not show a rail.
                await expect(page.locator('.bn-dm-dep-notice__title')).toBeVisible();
            }
            return;
        }

        // Bridge absent: redirected away, so the navigation must not advertise a
        // destination that cannot be reached.
        const messagesLinks = await page.locator('a[href*="/messages"]').count();
        expect(messagesLinks, 'navigation still links to /messages/ while the DM route redirects away').toBe(0);
    });
});
