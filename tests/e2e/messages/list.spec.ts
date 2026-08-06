import { test, expect } from '../_fixtures/auth.fixture';
import { sel, urls } from '../_fixtures/selectors';

/**
 * J-45 DM list.
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
            const placeholder = page.locator('.bn-messages-empty, .bn-messages-placeholder, [data-dm-empty]').first();
            const anyVisible =
                (await list.isVisible().catch(() => false)) ||
                (await placeholder.isVisible().catch(() => false));
            expect(anyVisible, 'DM route resolved but rendered neither a thread list nor an empty state').toBeTruthy();
            return;
        }

        // Bridge absent: redirected away, so the navigation must not advertise a
        // destination that cannot be reached.
        const messagesLinks = await page.locator('a[href*="/messages"]').count();
        expect(messagesLinks, 'navigation still links to /messages/ while the DM route redirects away').toBe(0);
    });
});
